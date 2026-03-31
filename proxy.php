<?php
/**
 * MyCashflow API -välityspalvelu välimuistilla
 * ============================================
 * - Hakee puhelinmallidatan MyCashflow API:sta kerran päivässä
 * - Tallentaa tuloksen cache.json-tiedostoon
 * - Seuraavat pyynnöt saavat vastauksen välimuistista välittömästi
 * - Pakota päivitys lisäämällä ?refresh=1 URL:iin (esim. testaukseen)
 */

// ─── ASETUKSET — MUOKKAA NÄMÄ ────────────────────────────────────────────────
define('MCF_DOMAIN',      'fany.mycashflow.fi');  // Ilman https://
define('MCF_API_USER', 'claude.joona@suojaapuhelin.fi');   // MyCashflow-käyttäjätunnuksesi
define('MCF_API_KEY',  '52a4340ed35ed011f0290cb7f4bcf0c9b8e890b8');    // API-avain asetuksista
define('MCF_PARENT_CAT',  42);                         // Puhelinten yläkategoria ID
define('MCF_ATTR_KEY',    'laitteen-lataustyyppi');    // Ominaisuuden tunniste
define('CACHE_HOURS',     24);                         // Kuinka usein päivitetään (tuntia)
// ─────────────────────────────────────────────────────────────────────────────

define('CACHE_FILE', __DIR__ . '/cache.json');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=3600');

$forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

// Palvele välimuistista jos se on tuore eikä päivitystä pakoteta
if (!$forceRefresh && file_exists(CACHE_FILE)) {
    $age = time() - filemtime(CACHE_FILE);
    if ($age < CACHE_HOURS * 3600) {
        header('X-Cache: HIT');
        header('X-Cache-Age: ' . $age . 's');
        readfile(CACHE_FILE);
        exit;
    }
}

// ─── HAE TUOREDATA API:STA ────────────────────────────────────────────────────
header('X-Cache: MISS');

$base    = 'https://' . MCF_DOMAIN . '/api/1.0';
$headers = [
    'Authorization: Basic ' . base64_encode(MCF_API_KEY . ':'),
    'Accept: application/json',
];

function mcf_get(string $url, array $headers): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err)            return ['error' => 'cURL-virhe: ' . $err];
    if ($status !== 200) return ['error' => "API HTTP $status — tarkista domain ja API-avain."];

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE)
        return ['error' => 'API palautti virheellisen JSON-vastauksen.'];

    return $data;
}

// 1. Hae alakategoriat yläkategorian alta
$catData = mcf_get("$base/categories?parent_id=" . MCF_PARENT_CAT . "&limit=200", $headers);
if (isset($catData['error'])) {
    // Jos välimuisti on olemassa (vaikka vanhentunut), palvele se virheen sijaan
    if (file_exists(CACHE_FILE)) {
        header('X-Cache: STALE');
        readfile(CACHE_FILE);
        exit;
    }
    echo json_encode($catData);
    exit;
}

$cats = $catData['categories'] ?? $catData;
if (empty($cats) || !is_array($cats)) {
    $err = ['error' => 'Yläkategoriasta ei löytynyt alakategorioita. Tarkista kategoria-ID ' . MCF_PARENT_CAT . '.'];
    echo json_encode($err);
    exit;
}

// 2. Hae lataustyyppi jokaisesta puhelinmallikategoriasta
$attrKey = strtolower(str_replace([' ', '_'], '-', MCF_ATTR_KEY));
$phones  = [];

foreach ($cats as $cat) {
    $catId   = $cat['id']   ?? null;
    $catName = $cat['name'] ?? ($cat['title'] ?? '');
    $catUrl  = $cat['url']  ?? ('https://' . MCF_DOMAIN . '/tuoteryhmat/' . ($cat['identifier'] ?? $catId));

    if (!$catId || !$catName) continue;

    $prodData = mcf_get("$base/products?category_id=$catId&limit=10", $headers);
    if (isset($prodData['error'])) continue;

    $prods       = $prodData['products'] ?? $prodData;
    $chargerType = null;

    foreach ((array)$prods as $prod) {
        $attrs = $prod['attributes']
            ?? ($prod['properties']
            ?? ($prod['variants'][0]['attributes'] ?? []));

        foreach ((array)$attrs as $attr) {
            $key = strtolower(str_replace([' ', '_'], '-',
                $attr['identifier'] ?? ($attr['key'] ?? ($attr['name'] ?? ''))
            ));
            if ($key !== $attrKey && strpos($key, 'lataustyyppi') === false) continue;

            $raw = is_array($attr['values'] ?? null)
                ? ($attr['values'][0] ?? '')
                : ($attr['value'] ?? '');
            $val = strtolower(trim(
                is_array($raw) ? ($raw['identifier'] ?? ($raw['value'] ?? '')) : $raw
            ));

            if ($val) { $chargerType = $val; break 2; }
        }
    }

    $parts = explode(' ', trim($catName));
    $phones[] = [
        'brand'       => $parts[0] ?? 'Muu',
        'name'        => $catName,
        'chargerType' => $chargerType ?? 'unknown',
        'categoryUrl' => $catUrl,
    ];
}

if (empty($phones)) {
    echo json_encode(['error' => 'Ei tuotetietoja. Tarkista kategoria-ID ja ominaisuuden tunniste.']);
    exit;
}

// 3. Tallenna välimuistiin ja palauta
$payload = json_encode([
    'phones'    => $phones,
    'count'     => count($phones),
    'cached_at' => date('c'),           // ISO 8601 aikaleima
    'cache_ttl' => CACHE_HOURS . 'h',
]);

file_put_contents(CACHE_FILE, $payload);
echo $payload;
