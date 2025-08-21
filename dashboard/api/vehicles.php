<?php
// dashboard/api/vehicles.php (debug wrapper)
// If you already have vehicles.php, you can replace its content with this wrapper
// or merge the debug behavior into your existing logic.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: s-maxage=30, stale-while-revalidate=120');

$debug = isset($_GET['debug']) ? true : false;

require_once __DIR__ . '/cache.php';

// --- BEGIN: your original fetching logic (simplified skeleton) ---
$CACHE_KEY = 'vehicles_payload';
$ttl = isset($_GET['ttl']) ? max(30, intval($_GET['ttl'])) : 300;

$cached = cache_get($CACHE_KEY, $ttl);
if ($cached !== false && !$debug) {
    echo json_encode(["status" => "ok", "cached" => true, "data" => $cached]);
    exit;
}

$url = getenv('VEHICLES_API_URL'); // optional upstream, else local build/demo
$user = getenv('MOBILE_USER');
$pass = getenv('MOBILE_PASSWORD');
$cust = getenv('CUSTOMER_NUMBERS');

$meta = [
    "env" => [
        "MOBILE_USER" => $user ? "***set***" : null,
        "MOBILE_PASSWORD" => $pass ? "***set***" : null,
        "CUSTOMER_NUMBERS" => $cust ?: null,
        "VEHICLES_API_URL" => $url ?: null,
    ]
];

$payload = null;
$errors = [];

if ($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code >= 400 || !$resp) {
        $errors[] = ["type" => "upstream", "http_code" => $code, "error" => $err ?: "empty response"];
    } else {
        $payload = json_decode($resp, true);
        if ($payload === null) {
            $errors[] = ["type" => "json", "message" => "invalid JSON from upstream"];
        }
    }
} else {
    // fallback demo
    $payload = [
        ["id" => 1, "title" => "Demo Fahrzeug", "price" => 19990, "fuel" => "Benzin", "gear" => "Automatik"],
    ];
    $meta["demo"] = true;
}

if ($payload !== null) {
    cache_set($CACHE_KEY, $payload);
    $out = ["status" => "ok", "cached" => false, "data" => $payload];
    if ($debug) $out["debug"] = $meta;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(502);
$out = ["status" => "error", "message" => "no data", "errors" => $errors];
if ($debug) $out["debug"] = $meta;
echo json_encode($out, JSON_UNESCAPED_UNICODE);
