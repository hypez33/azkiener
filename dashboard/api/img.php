<?php
declare(strict_types=1);

// --- Robust Image Proxy for Vercel (Serverless) ---
// Aufruf:  /img.php?src=ENCODED_URL   (Alias: ?u=)
// - streamt Bilder via cURL
// - setzt korrekte Content-Type Header
// - nutzt /tmp für flüchtiges Caching (pro Lambda-Container)
// - CDN-Cache über Cache-Control Header

// Sauberes Output-Buffering
while (ob_get_level() > 0) { ob_end_clean(); }
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Parameter
$src = $_GET['src'] ?? ($_GET['u'] ?? '');
$src = is_string($src) ? $src : '';
$debug = isset($_GET['debug']);

if (!$src) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"missing_param","need"=>"src|u"]);
  exit;
}
$src = urldecode($src);

// Nur http/https zulassen
if (!preg_match('~^https?://~i', $src)) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"invalid_scheme","src"=>$src]);
  exit;
}

// Einfache Sperre gegen SSRF in interne Netze
if (preg_match('~://(0\.0\.0\.0|127\.0\.0\.1|localhost|169\.254\.[0-9.]+|::1)~i', $src)) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(400);
  echo json_encode(["ok"=>false,"error"=>"blocked_host"]);
  exit;
}

// Temp-Cache im Lambda-Container
$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azk_img';
@mkdir($cacheDir, 0777, true);
$key  = substr(hash('sha256', $src), 0, 32);
$bin  = $cacheDir . DIRECTORY_SEPARATOR . $key . '.bin';
$meta = $cacheDir . DIRECTORY_SEPARATOR . $key . '.json';

// Cache-Hit?
if (is_file($bin) && is_file($meta)) {
  $m = json_decode((string)@file_get_contents($meta), true) ?: [];
  $ct = is_string($m['ct'] ?? '') ? $m['ct'] : 'image/jpeg';
  header('Content-Type: ' . $ct);
  header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=600');
  readfile($bin);
  exit;
}

// Laden via cURL
$ch = curl_init($src);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_MAXREDIRS      => 5,
  CURLOPT_CONNECTTIMEOUT => 5,
  CURLOPT_TIMEOUT        => 12,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_HTTPHEADER     => [
    'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:142.0) Gecko/20100101 Firefox/142.0'
  ],
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// Fehlerbehandlung
if ($body === false || $code < 200 || $code >= 400) {
  $payload = ["ok"=>false,"error"=>"fetch_failed","http_code"=>$code,"message"=>$err,"src"=>$src];
  if ($debug) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(502);
    echo json_encode($payload);
  } else {
    header('Content-Type: image/svg+xml; charset=utf-8');
    http_response_code(502);
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450"><rect width="100%" height="100%" fill="#eee"/><text x="50%" y="50%" text-anchor="middle" font-family="system-ui,Arial" font-size="18" fill="#999">Bild konnte nicht geladen werden (Proxy)</text></svg>';
  }
  exit;
}

// Content-Type absichern
if (!$ct || stripos($ct, 'image/') !== 0) {
  // via finfo heuristisch bestimmen
  if (function_exists('finfo_buffer')) {
    $fi = new finfo(FILEINFO_MIME_TYPE);
    $det = $fi->buffer($body);
    if (is_string($det) && stripos($det, 'image/') === 0) {
      $ct = $det;
    } else {
      $ct = 'image/jpeg';
    }
  } else {
    $ct = 'image/jpeg';
  }
}

// Cache speichern (flüchtig) & ausliefern
@file_put_contents($bin, $body, LOCK_EX);
@file_put_contents($meta, json_encode(["ct"=>$ct], JSON_UNESCAPED_SLASHES), LOCK_EX);

header('Content-Type: ' . $ct);
header('Cache-Control: public, max-age=300, s-maxage=300, stale-while-revalidate=600');
echo $body;
