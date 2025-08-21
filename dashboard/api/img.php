<?php
declare(strict_types=1);
header_remove(); // ensure no stale headers
$src = $_GET['src'] ?? ($_GET['u'] ?? '');
if (!$src) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(["error"=>"missing src/u"]);
    exit;
}
$parsed = parse_url($src);
if (!$parsed || !in_array(strtolower($parsed['scheme'] ?? ''), ['http','https'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode(["error"=>"invalid url"]);
    exit;
}
// Basic cache
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_img';
if (!file_exists($dir)) { @mkdir($dir, 0777, true); }
$cache = $dir . DIRECTORY_SEPARATOR . md5($src);
$meta  = $cache . '.json';
$ttl   = isset($_GET['ttl']) ? max(60, (int)$_GET['ttl']) : 3600;
if (file_exists($cache) && (time() - filemtime($cache) <= $ttl)) {
    $m = @json_decode(@file_get_contents($meta) ?: "{}", true);
    if (!empty($m['ct'])) header('Content-Type: ' . $m['ct']);
    header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=600');
    readfile($cache);
    exit;
}
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $src,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HEADER => true,
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($err || $code >= 400 || $resp === false) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(502);
    echo json_encode(["error"=>"fetch_failed","code"=>$code,"message"=>$err ?: ""]);
    exit;
}
$hs = strpos($resp, "\r\n\r\n");
$hs = $hs === false ? 0 : $hs + 4;
$headers = substr($resp, 0, $hs);
$body    = substr($resp, $hs);
$ct = 'image/jpeg';
foreach (explode("\r\n", $headers) as $line) {
    if (stripos($line, 'Content-Type:') === 0) { $ct = trim(substr($line, strlen('Content-Type:'))); break; }
}
@file_put_contents($cache, $body, LOCK_EX);
@file_put_contents($meta, json_encode(["ct"=>$ct]), LOCK_EX);
header('Content-Type: ' . $ct);
header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=600');
echo $body;
