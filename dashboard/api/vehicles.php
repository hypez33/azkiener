<?php
declare(strict_types=1);
ob_start();
ini_set('display_errors','0');
error_reporting(E_ALL);

function finish_json($payload, int $status=200){
  while (ob_get_level()>0) { ob_end_clean(); }
  header('Content-Type: application/json; charset=utf-8');
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

function cache_dir(): string {
  $d = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_cache';
  if (!file_exists($d)) { @mkdir($d, 0777, true); }
  return $d;
}
function cache_get(string $key, int $ttl){
  $p = cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
  if (!file_exists($p)) return null;
  if ((time() - filemtime($p)) > $ttl) return null;
  $t = @file_get_contents($p);
  return $t===false ? null : json_decode($t, true);
}
function cache_set(string $key, $value): void{
  $p = cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
  @file_put_contents($p, json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function http_basic_get_json(string $url, string $username, string $password){
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $username . ':' . $password,
    CURLOPT_HTTPHEADER => [
      'Accept: application/vnd.de.mobile.api+json',
      'Accept-Encoding: gzip',
      'User-Agent: Azkiener/1.0 (+vercel)'
    ],
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($err || $code >= 400 || !$resp) return [null, ["http_code"=>$code, "error"=>$err ?: "empty"]];
  $json = json_decode($resp, true);
  return $json===null ? [null, ["http_code"=>$code, "error"=>"invalid_json", "sample"=>substr($resp,0,120)]] : [$json, null];
}

function fmt_price_label($amount): string {
  if ($amount===null) return '0 €';
  $n = number_format((float)$amount, 0, ',', '.');
  return $n . ' €';
}
function gear_label($value){
  $v = strtolower((string)$value);
  if (strpos($v,'auto')!==false || strpos($v,'dsg')!==false) return 'Automatic_gear';
  return 'Manual_gear';
}
function first_year_from($val): int {
  if (is_string($val) && preg_match('/^([0-9]{4})/', $val, $m)) return (int)$m[1];
  return 0;
}

/* Robust image extraction (mirrors user's working utils.php) */
function pick_variant_url($arr) {
  foreach (['xxxl','xxl','xl','l','m','s','icon','url','href','src','originalUrl'] as $k) {
    if (isset($arr[$k]) && is_string($arr[$k]) && preg_match('~^https?://~i', $arr[$k])) {
      return $arr[$k];
    }
  }
  // Also handle representation[size] = url objects
  if (isset($arr['representation']) && is_array($arr['representation'])) {
    $pref = ['XXXL','XXL','XL','L','M','S','ICON'];
    $by = [];
    foreach ($arr['representation'] as $rep) {
      $size = strtoupper($rep['size'] ?? '');
      $url  = $rep['url'] ?? null;
      if ($size && $url) $by[$size] = $url;
    }
    foreach ($pref as $p) if (!empty($by[$p])) return $by[$p];
  }
  return '';
}
function image_from_detail($ad) {
  // 1) images: [ {xxxl|xxl|xl|...|url}, ... ] or strings
  if (isset($ad['images']) && is_array($ad['images'])) {
    $arr = $ad['images'];
    foreach ($arr as $item) {
      if (is_array($item)) { $u = pick_variant_url($item); if ($u) return $u; }
      elseif (is_string($item) && preg_match('~^https?://~i', $item)) { return $item; }
    }
  }
  // 2) media: { images|image|thumbnails|representations: [...] }
  if (isset($ad['media']) && is_array($ad['media'])) {
    foreach (['images','image','thumbnails','representations'] as $key) {
      $arr = $ad['media'][$key] ?? null;
      if (!$arr) continue;
      if (!is_array($arr)) $arr = [$arr];
      foreach ($arr as $item) {
        if (is_array($item)) { $u = pick_variant_url($item); if ($u) return $u; }
        elseif (is_string($item) && preg_match('~^https?://~i', $item)) { return $item; }
      }
    }
  }
  // 3) resources.images: [ {xxl|...|url} ]
  if (isset($ad['resources']['images']) && is_array($ad['resources']['images'])) {
    $arr = $ad['resources']['images'];
    if (!is_array($arr)) $arr = [$arr];
    foreach ($arr as $item) {
      if (is_array($item)) { $u = pick_variant_url($item); if ($u) return $u; }
    }
  }
  // 4) common single fields
  foreach (['imageUrl','thumbnailUrl','thumbUrl','pictureUrl','photoUrl'] as $k) {
    if (!empty($ad[$k]) && is_string($ad[$k]) && preg_match('~^https?://~i', $ad[$k])) return $ad[$k];
  }
  return null;
}

$user = getenv('MOBILE_USER') ?: '';
$pass = getenv('MOBILE_PASSWORD') ?: '';
$cust = getenv('CUSTOMER_NUMBERS') ?: '';
if ($user==='' || $pass==='' || $cust==='') {
  finish_json(["ts"=>time(),"data"=>[]], 200);
}

$ttl   = isset($_GET['ttl']) ? max(30, (int)$_GET['ttl']) : 300;
$force = isset($_GET['force']);
$limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 60;

$base  = 'https://services.mobile.de/search-api';
$query = 'customerNumber=' . rawurlencode($cust) . '&country=DE&sort.field=modificationTime&sort.order=DESCENDING&page.size=100';
$cacheKey = 'compat_schema_' . md5($query . '|limit=' . $limit);

if (!$force) {
  $cached = cache_get($cacheKey, $ttl);
  if ($cached!==null) finish_json($cached, 200);
}

/* 1) Search → build items quickly */
$items = [];
$page = 1; $maxPages = 30;
while ($page <= $maxPages && count($items) < $limit) {
  [$j,$e] = http_basic_get_json($base . '/search?' . $query . '&page.number=' . $page, $user, $pass);
  if ($e) break;
  $sr  = $j['searchResult'] ?? null;
  $ads = is_array($sr) ? ($sr['ads'] ?? []) : ($j['ads'] ?? []);
  if (!is_array($ads) || !count($ads)) break;
  foreach ($ads as $ad) {
    $adId = (string)($ad['mobileAdId'] ?? $ad['id'] ?? $ad['key'] ?? '');
    $url  = $ad['detailPageUrl'] ?? '';
    $title= $ad['title'] ?? (trim(($ad['make'] ?? '') . ' ' . ($ad['model'] ?? '')));
    $priceAmount = null;
    if (isset($ad['price']['consumerPriceGross'])) $priceAmount = (float)$ad['price']['consumerPriceGross'];
    elseif (isset($ad['price']['consumerPrice']['amount'])) $priceAmount = (float)$ad['price']['consumerPrice']['amount'];
    $fuel = $ad['fuel'] ?? "";
    $km   = (int)($ad['mileageInKm'] ?? $ad['mileage'] ?? 0);
    $year = first_year_from($ad['firstRegistration'] ?? ($ad['firstRegistrationDate'] ?? ''));
    $items[] = [
      "adId"       => $adId,
      "url"        => $url,
      "title"      => $title,
      "price"      => (int)($priceAmount ?? 0),
      "priceLabel" => fmt_price_label($priceAmount),
      "specs"      => number_format($km, 0, ',', '.') . ' km · ' . $fuel . ' · ' . gear_label($ad['gearbox'] ?? ($ad['transmission'] ?? '')),
      "fuel"       => $fuel,
      "km"         => $km,
      "year"       => $year,
      "img"        => "" // fill via details if necessary
    ];
    if (count($items) >= $limit) break;
  }
  $current = (int)($sr['currentPage'] ?? $page);
  $max     = (int)($sr['maxPages'] ?? $page);
  $maxPages = max(1, min($maxPages, $max));
  $page = $current + 1;
}

/* 2) Enrich missing price/image via detail */
foreach ($items as &$it) {
  if ($it["price"]>0 && $it["img"]!=="") continue;
  $key = null;
  if ($it["adId"]!=="") $key = preg_replace('/[^0-9]/','',(string)$it["adId"]);
  if ((!$key || $key==="") && $it["url"]) {
    if (preg_match('~id=([0-9]+)~', $it["url"], $m)) $key = $m[1];
  }
  if (!$key) continue;
  [$ad,$de] = http_basic_get_json($base . '/ad/' . rawurlencode((string)$key), $user, $pass);
  if ($de || !$ad) continue;
  if ($it["price"]==0) {
    $pa=null;
    if (isset($ad['price']['consumerPriceGross'])) $pa=(float)$ad['price']['consumerPriceGross'];
    elseif (isset($ad['price']['consumerPrice']['amount'])) $pa=(float)$ad['price']['consumerPrice']['amount'];
    if ($pa!==null){ $it["price"]=(int)$pa; $it["priceLabel"]=fmt_price_label($pa); }
  }
  if ($it["img"]==="") {
    $imgUrl = image_from_detail($ad);
    if ($imgUrl) {
      // Use absolute API path to avoid rewrite issues on deployment platforms
      $it["img"] = '/api/img.php?src=' . rawurlencode($imgUrl);
    }
  }
}
unset($it);

/* 3) Output legacy schema */
$result = ["ts"=>time(), "data"=>$items];
cache_set($cacheKey, $result);
finish_json($result, 200);
