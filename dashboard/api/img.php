<?php
// dashboard/api/img.php
// Vercel-safe Image Proxy with ephemeral cache in /tmp
// Usage: /img.php?src=<encoded absolute URL>&ttl=3600
// This file must live inside /api on Vercel.

// Validate input
$src = isset($_GET['src']) ? $_GET['src'] : null;
if (!$src) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["error" => "missing src parameter"]);
    exit;
}
// Basic allow-list: only http/https
$parsed = parse_url($src);
if (!$parsed || !in_array(strtolower($parsed['scheme'] ?? ''), ['http','https'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["error" => "invalid src url"]);
    exit;
}

$ttl = isset($_GET['ttl']) ? max(60, intval($_GET['ttl'])) : 3600;

// Build cache path in /tmp
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_img';
if (!file_exists($cacheDir)) { @mkdir($cacheDir, 0777, true); }
$cacheKey = md5($src);
$cachePath = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey;
$metaPath  = $cachePath . '.json';

// Serve from cache if fresh
if (file_exists($cachePath) && (time() - filemtime($cachePath) <= $ttl)) {
    $meta = @json_decode(@file_get_contents($metaPath) ?: "{}", true);
    if (!empty($meta['content_type'])) header('Content-Type: ' . $meta['content_type']);
    header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=600');
    readfile($cachePath);
    exit;
}

// Fetch from upstream
$ch = curl_init();
$headers = ['Accept: image/*,*/*;q=0.8'];
if (!empty($_GET['referer'])) $headers[] = 'Referer: ' . $_GET['referer'];
$ua = !empty($_GET['ua']) ? $_GET['ua'] : 'Mozilla/5.0 (compatible; AzkienerImageProxy/1.0)';

curl_setopt_array($ch, [
    CURLOPT_URL => $src,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_USERAGENT => $ua,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADER => true,
]);

$resp = curl_exec($ch);
if ($resp === false) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["error" => "curl_failed", "message" => curl_error($ch)]);
    curl_close($ch);
    exit;
}
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerStr  = substr($resp, 0, $headerSize);
$body       = substr($resp, $headerSize);
curl_close($ch);

if ($statusCode >= 400 || !$body) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(["error" => "bad_status", "status" => $statusCode]);
    exit;
}

// Determine content type from headers
$contentType = 'application/octet-stream';
foreach (explode("\r\n", $headerStr) as $line) {
    if (stripos($line, 'Content-Type:') === 0) {
        $contentType = trim(substr($line, strlen('Content-Type:')));
        break;
    }
}
header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=600');

// Save cache (best-effort)
@file_put_contents($cachePath, $body, LOCK_EX);
@file_put_contents($metaPath, json_encode(["content_type" => $contentType]), LOCK_EX);

echo $body;
