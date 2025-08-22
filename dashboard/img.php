<?php
// dashboard/img.php
// Lightweight image proxy with ephemeral caching for Vercel.
// Usage: /img.php?src=<url-encoded-absolute-url>&ttl=3600
// Optional: &referer=<referer-header>, &ua=<user-agent>
// NOTE: No resizing server-side (GD/Imagick may not be available in serverless).

declare(strict_types=1);
header_remove('X-Powered-By');

// Validate & normalize input
$src = isset($_GET['src']) ? trim((string)$_GET['src']) : '';
if ($src === '' || !preg_match('~^https?://~i', $src)) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Missing or invalid src URL']);
  exit;
}

$ttl = isset($_GET['ttl']) ? max(60, (int)$_GET['ttl']) : 3600; // default 1h
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_img';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
$cacheKey = md5($src);
$cachePath = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey;

// Serve from cache if fresh
if (file_exists($cachePath)) {
  $age = time() - filemtime($cachePath);
  if ($age <= $ttl) {
    // Read headers file if present
    $meta = @json_decode(@file_get_contents($cachePath . '.json'), true);
    if (isset($meta['content_type'])) {
      header('Content-Type: ' . $meta['content_type']);
    } else {
      header('Content-Type: image/jpeg'); // fallback
    }
    header('Cache-Control: public, max-age=300, s-maxage=600, stale-while-revalidate=1200');
    readfile($cachePath);
    exit;
  }
}

// Fetch upstream
$ch = curl_init();
$headers = ['Accept: image/*,*/*;q=0.8'];
if (isset($_GET['referer'])) $headers[] = 'Referer: ' . $_GET['referer'];
$ua = isset($_GET['ua']) ? (string)$_GET['ua'] : 'azkiener-img-proxy/1.0';
curl_setopt_array($ch, [
  CURLOPT_URL => $src,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_MAXREDIRS => 4,
  CURLOPT_TIMEOUT => 15,
  CURLOPT_HTTPHEADER => $headers,
  CURLOPT_USERAGENT => $ua,
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($err || $code >= 400 || !$body) {
  http_response_code(502);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Upstream fetch failed', 'code' => $code, 'detail' => $err]);
  exit;
}

// Persist to cache (best-effort)
@file_put_contents($cachePath, $body, LOCK_EX);
@file_put_contents($cachePath . '.json', json_encode(['content_type' => $contentType ?: 'image/jpeg']), LOCK_EX);

// Serve
header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
header('Cache-Control: public, max-age=300, s-maxage=600, stale-while-revalidate=1200');
echo $body;
