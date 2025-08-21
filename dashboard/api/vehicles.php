
<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: s-maxage=30, stale-while-revalidate=120');

<?php
// Common helpers for cache + fetch
function md_cache_dir() {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_cache';
    if (!file_exists($dir)) { @mkdir($dir, 0777, true); }
    return $dir;
}
function md_cache_get($key, $ttl) {
    $p = md_cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    if (!file_exists($p)) return null;
    if ((time() - filemtime($p)) > $ttl) return null;
    $txt = @file_get_contents($p);
    if ($txt === false) return null;
    $j = json_decode($txt, true);
    return $j;
}
function md_cache_set($key, $value) {
    $p = md_cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    @file_put_contents($p, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function http_basic_get_json($url, $username, $password, $headers = []) {
    $ch = curl_init();
    $allHeaders = array_merge([
        'Accept: application/vnd.de.mobile.api+json',
        'Accept-Encoding: gzip',
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
    if ($err || $code >= 400 || !$resp) {
        return [null, ["http_code"=>$code, "error"=>$err ?: "empty response", "bodySample"=> substr((string)$resp,0,200) ]];
    }
    $json = json_decode($resp, true);
    if ($json === null) {
        return [null, ["http_code"=>$code, "error"=>"invalid_json", "bodySample"=> substr($resp,0,200) ]];
    }
    return [$json, null];
}
function norm_text($s){ return is_string($s) ? trim($s) : $s; }

$user = getenv('MOBILE_USER');
$pass = getenv('MOBILE_PASSWORD');
$cust = getenv('CUSTOMER_NUMBERS');
if (!$user || !$pass || !$cust) {
    http_response_code(500);
    echo json_encode(["status"=>"error","message"=>"Missing env MOBILE_USER/MOBILE_PASSWORD/CUSTOMER_NUMBERS"]);
    exit;
}
$ttl = isset($_GET['ttl']) ? max(30, intval($_GET['ttl'])) : 300;
$force = isset($_GET['force']);
$pageSize = 100;
$base = "https://services.mobile.de/search-api/search";
// IMPORTANT: Search API requires at least one non-numeric/bool parameter -> include country=DE (string)
$query = "customerNumber=" . urlencode($cust) . "&country=DE&sort.field=modificationTime&sort.order=DESCENDING&page.size=" . $pageSize;
$cacheKey = "mobilede_" . md5($query);

if (!$force) {
    $cached = md_cache_get($cacheKey, $ttl);
    if ($cached) { echo json_encode(["status"=>"ok","cached"=>true,"data"=>$cached]); exit; }
}

$allAds = [];
$page = 1;
$maxPages = 30; // safety cap
while ($page <= $maxPages) {
    $url = $base . "?" . $query . "&page.number=" . $page;
    list($json, $err) = http_basic_get_json($url, $user, $pass);
    if ($err) {
        http_response_code(502);
        echo json_encode(["status"=>"error","message"=>"fetch_failed","page"=>$page,"details"=>$err]);
        exit;
    }
    // New JSON format: searchResult.ads is array
    $result = $json;
    if (isset($result['searchResult'])) {
        $sr = $result['searchResult'];
        $ads = $sr['ads'] ?? [];
        $allAds = array_merge($allAds, $ads);
        $current = intval($sr['currentPage'] ?? $page);
        $max = intval($sr['maxPages'] ?? $page);
        if ($current >= $max or count($ads) == 0) break;
        $page = $current + 1;
        $maxPages = min($maxPages, max(1,$max));
    } else {
        // Legacy format fallback
        $ads = $result['ads'] ?? [];
        $allAds = array_merge($allAds, $ads);
        if (count($ads) < $pageSize) break;
        $page += 1;
    }
}

// Normalize vehicles for frontend: id, title, price, images[0], fuel, gear, mileage, firstRegistration, make, model, powerKW, url
$out = [];
foreach ($allAds as $ad) {
    $veh = $ad['ad'] ?? $ad; // depending on JSON nesting
    $id  = $veh['id'] ?? ($veh['key'] ?? null);
    $title = $veh['title'] ?? null;
    if (!$title) {
        // build a title from make/model
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
            $images[] = $img['url'] ?? null;
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
        "title" => norm_text($title),
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

md_cache_set($cacheKey, $out);
echo json_encode(["status"=>"ok","cached"=>false,"data"=>$out]);
