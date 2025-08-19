<?php
require __DIR__ . '/../lib/utils.php';

try {
    $c = cfg();
    $cache = read_cache();
    $age = time() - ($cache['ts'] ?? 0);
    json_response([
        'ok'      => true,
        'cached'  => count($cache['data'] ?? []),
        'ageSec'  => $age,
        'ttlSec'  => $c['CACHE_TTL_SECONDS'],
        'proxied' => $c['PROXY_IMAGES'],
    ]);
} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}
