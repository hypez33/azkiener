<?php
// dashboard/api/vehicles.php
// Example PHP endpoint for Vercel Serverless Functions.
// Reads from an external API given by env vars VEHICLES_API_URL and VEHICLES_API_KEY (optional).
// Caches the JSON for 5 minutes in /tmp via cache.php.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: s-maxage=60, stale-while-revalidate=300');

require_once __DIR__ . '/cache.php';

$CACHE_KEY = 'vehicles_v1';
$ttl = isset($_GET['ttl']) ? max(30, intval($_GET['ttl'])) : 300; // allow override for testing

// Serve from cache if available
$cached = cache_get($CACHE_KEY, $ttl);
if ($cached !== false) {
    echo json_encode(["status" => "ok", "cached" => true, "data" => $cached], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = getenv('VEHICLES_API_URL'); // e.g., your mobile.de proxy or existing PHP endpoint elsewhere
$apiKey = getenv('VEHICLES_API_KEY'); // optional

if (!$url) {
    // Fallback/local demo payload so the endpoint doesn't break if env vars are missing.
    $demo = [
        ["id" => 1, "title" => "Demo Fahrzeug", "price" => 19990, "fuel" => "Benzin", "gear" => "Automatik"],
    ];
    cache_set($CACHE_KEY, $demo);
    echo json_encode(["status" => "ok", "cached" => false, "demo" => true, "data" => $demo], JSON_UNESCAPED_UNICODE);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => array_filter([
        'Accept: application/json',
        $apiKey ? 'Authorization: Bearer ' . $apiKey : null
    ]),
]);

$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $code >= 400 || !$resp) {
    http_response_code(502);
    echo json_encode([
        "status" => "error",
        "message" => "Upstream fetch failed",
        "http_code" => $code,
        "error" => $err
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = json_decode($resp, true);
if ($json === null) {
    http_response_code(502);
    echo json_encode(["status" => "error", "message" => "Invalid JSON from upstream"], JSON_UNESCAPED_UNICODE);
    exit;
}

// Optionally normalize/shape the payload here...
cache_set($CACHE_KEY, $json);

echo json_encode(["status" => "ok", "cached" => false, "data" => $json], JSON_UNESCAPED_UNICODE);
