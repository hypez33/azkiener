<?php
// /api/img.php
// Ein leichtgewichtiger Bild-Proxy mit Caching für Vercel.
// Verwendung: /api/img.php?u=<URL-codierte-absolute-Bild-URL>
declare(strict_types=1);

header_remove('X-Powered-By');

// Parameter 'u' für Kompatibilität mit dem bestehenden Frontend-Code.
$src = trim($_GET['u'] ?? '');

if (!filter_var($src, FILTER_VALIDATE_URL)) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Missing or invalid URL parameter "u"']);
  exit;
}

$ttl = 86400; // Bilder für 24 Stunden zwischenspeichern
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_img_cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }

$cacheKey = md5($src);
$cachePath = $cacheDir . DIRECTORY_SEPARATOR . $cacheKey;
$metaPath = $cachePath . '.json';

// Aus dem Cache bereitstellen, wenn frisch
if (file_exists($cachePath) && (time() - filemtime($cachePath)) <= $ttl) {
    $meta = @json_decode(@file_get_contents($metaPath), true);
    header('Content-Type: ' . ($meta['content_type'] ?? 'image/jpeg'));
    header('Cache-Control: public, max-age=' . $ttl);
    header('X-Image-Cache: hit');
    readfile($cachePath);
    exit;
}

// Bild von der Originalquelle abrufen
$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL => $src,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_MAXREDIRS => 5,
  CURLOPT_TIMEOUT => 20,
  CURLOPT_HTTPHEADER => ['Accept: image/*'],
  CURLOPT_USERAGENT => 'AzkienerImageProxy/1.2 (Vercel)',
]);

$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err = curl_error($ch);
curl_close($ch);

if ($err || $code >= 400 || !$body) {
  http_response_code(502); // Bad Gateway
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Upstream image fetch failed', 'code' => $code, 'detail' => $err]);
  exit;
}

// Im Cache speichern
@file_put_contents($cachePath, $body, LOCK_EX);
@file_put_contents($metaPath, json_encode(['content_type' => $contentType]), LOCK_EX);

// An den Client ausliefern
header('Content-Type: ' . ($contentType ?: 'image/jpeg'));
header('Cache-Control: public, max-age=600');
header('X-Image-Cache: miss');
echo $body;
