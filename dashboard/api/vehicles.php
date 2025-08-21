<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: s-maxage=30, stale-while-revalidate=120');

// ------- Helpers (no closing ?> to avoid stray output) -------
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
    return json_decode($txt, true);
}
function cache_set(string $key, $value): void {
    $p = cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    @file_put_contents($p, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function http_basic_get(string $url, string $username, string $password, array $headers = []) {
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
    return [$resp, $code, $err];
}
function http_basic_get_json(string $url, string $username, string $password, array $headers = []) {
    [$resp, $code, $err] = http_basic_get($url, $username, $password, $headers);
    if ($err || $code >= 400 || $resp === false || $resp === '') {
        return [null, ["http_code"=>$code, "error"=>$err ?: "empty response"]];
    }
    $json = json_decode($resp, true);
    if ($json === null) {
        return [null, ["http_code"=>$code, "error"=>"invalid_json", "sample"=>substr($resp,0,200)]];
    }
    return [$json, null];
}
function t($v){ return is_string($v) ? trim($v) : $v; }

$user = getenv('MOBILE_USER') ?: '';
$pass = getenv('MOBILE_PASSWORD') ?: '';
$cust = getenv('CUSTOMER_NUMBERS') ?: '';
if ($user === '' || $pass === '' || $cust === '') {
    http_response_code(500);
    echo json_encode(["status"=>"error","message"=>"Missing env MOBILE_USER/MOBILE_PASSWORD/CUSTOMER_NUMBERS"]);
    exit;
}

$ttl    = isset($_GET['ttl']) ? max(30, (int)$_GET['ttl']) : 300;
$force  = isset($_GET['force']);
$limit  = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 60; // cap total details
$pageSize = 100;
$base   = 'https://services.mobile.de/search-api';
$query  = 'customerNumber=' . rawurlencode($cust) . '&country=DE&sort.field=modificationTime&sort.order=DESCENDING&page.size=' . $pageSize;

$cacheKey = 'mobilede_detail_' . md5($query . '|limit=' . $limit);
if (!$force) {
    $cached = cache_get($cacheKey, $ttl);
    if ($cached !== null) {
        echo json_encode(["status"=>"ok","cached"=>true,"data"=>$cached], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 1) Fetch search pages to collect ad keys (and URLs)
$keys = [];
$page = 1;
$maxPages = 30;
while ($page <= $maxPages && count($keys) < $limit) {
    $url = $base . '/search?' . $query . '&page.number=' . $page;
    [$json, $err] = http_basic_get_json($url, $user, $pass);
    if ($err) {
        http_response_code(502);
        echo json_encode(["status"=>"error","message"=>"search_fetch_failed","page"=>$page,"details"=>$err], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sr = $json['searchResult'] ?? null;
    $ads = is_array($sr) ? ($sr['ads'] ?? []) : ($json['ads'] ?? []);
    if (!is_array($ads) || !count($ads)) break;
    foreach ($ads as $ad) {
        // New JSON search item may include lightweight ad; ensure we capture key and link
        $key = $ad['key'] ?? ($ad['ad']['key'] ?? null);
        $adUrl = $ad['url'] ?? ($ad['ad']['url'] ?? null);
        if (!$key && $adUrl) {
            // e.g. https://services.mobile.de/search-api/ad/15012
            if (preg_match('~/ad/(\d+)~', $adUrl, $m)) { $key = $m[1]; }
        }
        if ($key) {
            $keys[] = $key;
            if (count($keys) >= $limit) break;
        }
    }
    $current = (int)($sr['currentPage'] ?? $page);
    $max     = (int)($sr['maxPages'] ?? $page);
    $maxPages = max(1, min($maxPages, $max));
    $page = $current + 1;
}

// 2) Fetch full details for each ad-key
$out = [];
foreach ($keys as $key) {
    $detailUrl = $base . '/ad/' . rawurlencode((string)$key);
    [$adJson, $derr] = http_basic_get_json($detailUrl, $user, $pass);
    if ($derr || !$adJson) { continue; }
    // According to docs, new JSON fields include: mobileAdId, detailPageUrl, images, price.consumerPriceGross
    $id = $adJson['mobileAdId'] ?? ($adJson['adKey'] ?? $key);
    $price = null;
    if (isset($adJson['price']['consumerPriceGross'])) {
        $price = (float)$adJson['price']['consumerPriceGross'];
    } elseif (isset($adJson['price']['consumerPrice']['amount'])) {
        $price = (float)$adJson['price']['consumerPrice']['amount'];
    }
    $images = [];
    if (isset($adJson['images']['image']) && is_array($adJson['images']['image'])) {
        foreach ($adJson['images']['image'] as $img) {
            if (!empty($img['url'])) $images[] = $img['url'];
        }
    } elseif (isset($adJson['images']) && is_array($adJson['images'])) {
        // Some formats might give a flat array
        foreach ($adJson['images'] as $img) {
            if (is_array($img) && !empty($img['url'])) $images[] = $img['url'];
        }
    }
    $title = $adJson['title'] ?? (trim(($adJson['make'] ?? '') . ' ' . ($adJson['model'] ?? '')));
    $out[] = [
        "id" => $id,
        "title" => $title,
        "price" => $price,
        "images" => $images,
        "fuel" => $adJson['fuel'] ?? null,
        "gear" => $adJson['gearbox'] ?? ($adJson['transmission'] ?? null),
        "mileage" => $adJson['mileage'] ?? ($adJson['mileageInKm'] ?? null),
        "firstRegistration" => $adJson['firstRegistration'] ?? ($adJson['firstRegistrationDate'] ?? null),
        "powerKW" => $adJson['power'] ?? ($adJson['powerInKW'] ?? null),
        "make" => $adJson['make'] ?? null,
        "model" => $adJson['model'] ?? null,
        "url" => $adJson['detailPageUrl'] ?? null
    ];
}

cache_set($cacheKey, $out);
echo json_encode(["status"=>"ok","cached"=>false,"data"=>$out], JSON_UNESCAPED_UNICODE);
