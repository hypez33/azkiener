<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: s-maxage=30, stale-while-revalidate=120');

function cache_dir(): string {
    $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_cache';
    if (!file_exists($d)) { @mkdir($d, 0777, true); }
    return $d;
}
function cache_get(string $key, int $ttl) {
    $p = cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    if (!file_exists($p)) return null;
    if ((time() - filemtime($p)) > $ttl) return null;
    $t = @file_get_contents($p);
    if ($t === false) return null;
    return json_decode($t, true);
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
function fmt_price_label($amount): string {
    if ($amount === null) return '0 €';
    $n = number_format((float)$amount, 0, ',', '.');
    return $n . ' €';
}
function gear_label($value) {
    $v = strtolower((string)$value);
    if (strpos($v, 'auto') !== false || strpos($v, 'dsg') !== false) return 'Automatic_gear';
    return 'Manual_gear';
}

$user = getenv('MOBILE_USER') ?: '';
$pass = getenv('MOBILE_PASSWORD') ?: '';
$cust = getenv('CUSTOMER_NUMBERS') ?: '';
if ($user === '' || $pass === '' || $cust === '') {
    http_response_code(500);
    echo json_encode({"error":"Missing env MOBILE_USER/MOBILE_PASSWORD/CUSTOMER_NUMBERS"});
    exit;
}
$ttl    = isset($_GET['ttl']) ? max(30, (int)$_GET['ttl']) : 300;
$force  = isset($_GET['force']);
$limit  = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 60;

$base   = 'https://services.mobile.de/search-api';
$query  = 'customerNumber=' . rawurlencode($cust) . '&country=DE&sort.field=modificationTime&sort.order=DESCENDING&page.size=100';
$cacheKey = 'compat_schema_' . md5($query . '|limit=' . $limit);

if (!$force) {
    $cached = cache_get($cacheKey, $ttl);
    if ($cached !== null) {
        echo json_encode($cached, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Collect keys from search
$keys = [];
$page = 1;
$maxPages = 30;
while ($page <= $maxPages && count($keys) < $limit) {
    $url = $base . '/search?' . $query . '&page.number=' . $page;
    [$j, $e] = http_basic_get_json($url, $user, $pass);
    if ($e) {
        http_response_code(502);
        echo json_encode(["error"=>"search_fetch_failed","page"=>$page,"details"=>$e], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sr  = $j['searchResult'] ?? null;
    $ads = is_array($sr) ? ($sr['ads'] ?? []) : ($j['ads'] ?? []);
    if (!is_array($ads) || !count($ads)) break;
    foreach ($ads as $ad) {
        $key = $ad['key'] ?? ($ad['ad']['key'] ?? null);
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

// Fetch details -> compat schema
$out = [];
foreach ($keys as $key) {
    $detailUrl = $base . '/ad/' . rawurlencode((string)$key);
    [$ad, $derr] = http_basic_get_json($detailUrl, $user, $pass);
    if ($derr || !$ad) continue;

    $adId = (string)($ad['mobileAdId'] ?? $ad['adKey'] ?? $key);
    $url  = $ad['detailPageUrl'] ?? ('https://suchen.mobile.de/fahrzeuge/details.html?id=' . rawurlencode((string)$adId));
    $title= $ad['title'] ?? (trim(($ad['make'] ?? '') . ' ' . ($ad['model'] ?? '')));
    $priceAmount = null;
    if (isset($ad['price']['consumerPriceGross'])) {
        $priceAmount = (float)$ad['price']['consumerPriceGross'];
    } elseif (isset($ad['price']['consumerPrice']['amount'])) {
        $priceAmount = (float)$ad['price']['consumerPrice']['amount'];
    }
    $fuel = $ad['fuel'] ?? "";
    $km   = (int)($ad['mileageInKm'] ?? $ad['mileage'] ?? 0);
    $firstReg = $ad['firstRegistration'] ?? ($ad['firstRegistrationDate'] ?? '');
    $year = 0;
    if (is_string($firstReg) && preg_match('/^([0-9]{4})/', $firstReg, $m)) { $year = (int)$m[1]; }

    $imgUrl = null;
    if (isset($ad['images']['image']) && is_array($ad['images']['image'])) {
        foreach ($ad['images']['image'] as $img) {
            if (!empty($img['url'])) { $imgUrl = $img['url']; break; }
        }
    } elseif (isset($ad['images']) && is_array($ad['images'])) {
        foreach ($ad['images'] as $img) {
            if (is_array($img) && !empty($img['url'])) { $imgUrl = $img['url']; break; }
        }
    }
    $imgProxy = $imgUrl ? ('/img.php?src=' . rawurlencode($imgUrl)) : "";

    $out[] = [
        "adId"       => $adId,
        "url"        => $url,
        "title"      => $title,
        "price"      => (int)($priceAmount ?? 0),
        "priceLabel" => fmt_price_label($priceAmount),
        "specs"      => number_format($km, 0, ',', '.') . ' km · ' . $fuel . ' · ' . gear_label($ad['gearbox'] ?? ($ad['transmission'] ?? '')),
        "fuel"       => $fuel,
        "km"         => $km,
        "year"       => $year,
        "img"        => $imgProxy
    ];
}

$result = ["ts" => time(), "data" => $out];
cache_set($cacheKey, $result);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
