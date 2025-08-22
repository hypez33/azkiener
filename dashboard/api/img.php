<?php
declare(strict_types=1);
// Hardened image proxy for Vercel
// Supports: ?src=... or ?u=...
// Debug mode: add &debug=1 to get JSON diagnostics instead of image

// Make sure no stray output happens
while (ob_get_level() > 0) { ob_end_clean(); }
ini_set('display_errors','0');
error_reporting(E_ALL);

$debug = isset($_GET['debug']);

$src = $_GET['src'] ?? ($_GET['u'] ?? '');
if (!$src) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"missing src/u"]);
  exit;
}
$parsed = parse_url($src);
$scheme = strtolower($parsed['scheme'] ?? '');
if (!in_array($scheme, ['http','https'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"invalid scheme","scheme"=>$scheme]);
  exit;
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_img';
if (!file_exists($dir)) { @mkdir($dir, 0777, true); }
$key = md5($src);
$bin = $dir . DIRECTORY_SEPARATOR . $key . '.bin';
$meta= $dir . DIRECTORY_SEPARATOR . $key . '.json';
$ttl = isset($_GET['ttl']) ? max(60, (int)$_GET['ttl']) : 3600;

// Serve from cache if fresh
if (file_exists($bin) && (time() - filemtime($bin) <= $ttl)) {
  $m = @json_decode(@file_get_contents($meta) ?: "{}", true);
  $ct = $m['ct'] ?? 'image/jpeg';
  if ($debug) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["ok"=>true,"cached"=>true,"content_type"=>$ct,"bytes"=>filesize($bin),"src"=>$src]);
    exit;
  }
  header('Content-Type: ' . $ct);
  header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=600');
  readfile($bin);
  exit;
}

// Fetch from origin
$headers = [];
$ct = 'image/jpeg';
$code = 0;
$err  = '';

$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => $src,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 25,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_MAXREDIRS => 5,
  CURLOPT_ENCODING => '', // accept gzip/deflate
  CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AzkienerBot/1.0; +https://example.com)',
  CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers, &$ct) {
    $len = strlen($header);
    $header = trim($header);
    if (strpos(strtolower($header), 'content-type:') === 0) {
      $ct = trim(substr($header, strlen('content-type:')));
    }
    $headers[] = $header;
    return $len;
  }
]);
$body = curl_exec($ch);
$err  = curl_error($ch) ?: '';
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($debug) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(["ok"=>($body!==false && $code>=200 && $code<400), "http_code"=>$code, "error"=>$err, "content_type"=>$ct, "src"=>$src, "headers"=>$headers, "body_len"=>($body!==false?strlen($body):0)]);
  exit;
}

if ($body === false || $code < 200 || $code >= 400) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(502);
  echo json_encode(["ok"=>false,"error"=>"fetch_failed","http_code"=>$code,"message"=>$err,"src"=>$src]);
  exit;
}

// Persist cache and stream
@file_put_contents($bin, $body, LOCK_EX);
@file_put_contents($meta, json_encode(["ct"=>$ct, "code"=>$code], JSON_UNESCAPED_SLASHES), LOCK_EX);
header('Content-Type: ' . $ct);
header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=600');
echo $body;
