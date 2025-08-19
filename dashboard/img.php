<?php
require __DIR__ . '/lib/utils.php';

ensure_storage();
$c = cfg();

$u = $_GET['u'] ?? '';
if (!$u || !preg_match('~^https?://~i', $u)) {
    http_response_code(400); echo 'invalid url'; exit;
}

$hash = sha1($u);
$path = $c['IMG_CACHE_DIR'] . '/' . $hash . '.bin';
$ctypePath = $c['IMG_CACHE_DIR'] . '/' . $hash . '.type';

if (file_exists($path) && file_exists($ctypePath)) {
    $ctype = trim(@file_get_contents($ctypePath)) ?: 'image/jpeg';
    header('Content-Type: '.$ctype);
    header('Cache-Control: public, max-age=86400');
    readfile($path);
    exit;
}

$ch = curl_init($u);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => $c['HTTP_TIMEOUT'],
    CURLOPT_TIMEOUT        => $c['HTTP_TIMEOUT'],
    CURLOPT_USERAGENT      => 'ak-mobile-img-proxy/1.0',
]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
curl_close($ch);

if ($code >= 400 || $body === false || strlen($body) === 0) {
    http_response_code(502);
    echo 'upstream error';
    exit;
}

@file_put_contents($path, $body);
@file_put_contents($ctypePath, $ctype);

header('Content-Type: '.$ctype);
header('Cache-Control: public, max-age=86400');
echo $body;
