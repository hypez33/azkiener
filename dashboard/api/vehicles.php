<?php
declare(strict_types=1);

// IMPORTANT: This file must be saved as UTF-8 **without BOM** and with **no whitespace before <?php**.

// Strictly set headers before any output:
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: s-maxage=30, stale-while-revalidate=120');

// --- Helpers (no output) ---
function cache_dir(): string {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_cache';
    if (!file_exists($dir)) { @mkdir($dir, 0777, true); }
    return $dir;
}
function cache_get(string $key, int $ttl) {
    $p = cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    if (!file_exists($p)) return null;
    if ((time() - filemtime($p)) > $ttl) return null;
    $txt = @file_get_contents($p);
    if ($txt === false) return null;
    $j = json_decode($txt, true);
    return $j;
}
function cache_set(string $key, $value): void {
    $p = cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    @file_put_contents($p, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function http_basic_get_json(string $url, string $username, string $password, array $headers = []) {
    $ch = curl_init();
    $allHeaders = array_merge([
        'Accept: application/vnd.de.mobile.api+json',
        'Accept-Encoding: gzip',
        'User-Agent: Azkiener/1.0 (+vercel)'
    ], $headers);
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_HTTPHEADER => $allHeaders,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err || $code >= 400 || $resp === false || $resp === '') {
        return [null, ["http_code"=>$code, "error"=>$err ?: "empty response"]];
    }
    $json = json_decode($resp, true);
    if ($json === null) {
        return [null, ["http_code"=>$code, "error"=>"invalid_json", "sample"=>substr($resp,0,200)]];
    }
    return [$json, null];
}
function t($v) { return is_string($v) ? trim($v) : $v; }

// --- Env ---
$user = getenv('MOBILE_USER') ?: '';
$pass = getenv('MOBILE_PASSWORD') ?: '';
$cust = getenv('CUSTOMER_NUMBERS') ?: '';
if ($user === '' || $pass === '' || $cust === '') {
    http_response_code(500);
    echo json_encode(["status"=>"error","message"=>"Missing env MOBILE_USER/MOBILE_PASSWORD/CUSTOMER_NUMBERS"]);
    exit;
}

// --- Params ---
$ttl   = isset($_GET['ttl']) ? max(30, (int)$_GET['ttl']) : 300;
$force = isset($_GET['force']);
$pageSize = 100;

// --- Build query ---
$base = 'https://services.mobile.de/search-api/search';
$query = 'customerNumber=' . rawurlencode($cust) . '&country=DE&sort.field=modificationTime&sort.order=DESCENDING&page.size=' . $pageSize;
$cacheKey = 'mobilede_' . md5($query);

if (!$force) {
    $cached = cache_get($cacheKey, $ttl);
    if ($cached !== null) {
        echo json_encode(["status"=>"ok","cached"=>true,"data"=>$cached], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// --- Fetch pages ---
$allAds = [];
$page = 1;
$maxPages = 30; // safety
while ($page <= $maxPages) {
    $url = $base . '?' . $query . '&page.number=' . $page;
    [$json, $err] = http_basic_get_json($url, $user, $pass);
    if ($err) {
        http_response_code(502);
        echo json_encode(["status"=>"error","message"=>"fetch_failed","page"=>$page,"details"=>$err], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // New JSON format contains searchResult
    $sr = $json['searchResult'] ?? null;
    $ads = [];
    if (is_array($sr)) {
        $ads = $sr['ads'] ?? [];
        $current = (int)($sr['currentPage'] ?? $page);
        $max     = (int)($sr['maxPages'] ?? $page);
        $maxPages = max(1, min($maxPages, $max));
        $page = $current + 1;
    } else {
        // legacy fallback
        $ads = $json['ads'] ?? [];
        $page += 1;
        if (count($ads) < $pageSize) { $maxPages = 0; }
    }
    if (!is_array($ads) || count($ads) === 0) { break; }
    $allAds = array_merge($allAds, $ads);
}

// --- Normalize ---
$out = [];
foreach ($allAds as $ad) {
    $veh = isset($ad['ad']) && is_array($ad['ad']) ? $ad['ad'] : $ad;
    $id  = $veh['id'] ?? ($veh['key'] ?? null);
    $title = $veh['title'] ?? null;
    if (!$title) {
        $make = $veh['make'] ?? ($veh['classification']['make'] ?? null);
        $model= $veh['model'] ?? ($veh['classification']['model'] ?? null);
        $title = trim(($make ? $make . ' ' : '') . ($model ?: ''));
    }
    $price = null;
    if (isset($veh['price']['consumerPrice']['amount'])) {
        $price = $veh['price']['consumerPrice']['amount'];
    } elseif (isset($veh['price']['gross']['amount'])) {
        $price = $veh['price']['gross']['amount'];
    }
    $images = [];
    if (isset($veh['images']['image']) && is_array($veh['images']['image'])) {
        foreach ($veh['images']['image'] as $img) {
            if (isset($img['url'])) { $images[] = $img['url']; }
        }
    }
    $fuel = $veh['fuel'] ?? null;
    $gear = $veh['gearbox'] ?? ($veh['transmission'] ?? null);
    $mileage = $veh['mileage'] ?? ($veh['mileageInKm'] ?? null);
    $firstReg = $veh['firstRegistrationDate'] ?? ($veh['firstRegistration'] ?? null);
    $powerKW = $veh['power'] ?? ($veh['powerInKW'] ?? null);
    $make = $veh['make'] ?? ($veh['classification']['make'] ?? null);
    $model= $veh['model'] ?? ($veh['classification']['model'] ?? null);
    $detailUrl = $veh['adDetailPageUrl'] ?? null;

    $out[] = [
        "id" => $id,
        "title" => t($title),
        "price" => $price,
        "images" => $images,
        "fuel" => $fuel,
        "gear" => $gear,
        "mileage" => $mileage,
        "firstRegistration" => $firstReg,
        "powerKW" => $powerKW,
        "make" => $make,
        "model" => $model,
        "url" => $detailUrl
    ];
}

// --- Cache and respond ---
cache_set($cacheKey, $out);
echo json_encode(["status"=>"ok","cached"=>false,"data"=>$out], JSON_UNESCAPED_UNICODE);
