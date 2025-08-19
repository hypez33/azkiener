<?php
require __DIR__ . '/../lib/utils.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'POST required'], 405);
}

try {
    $cache = get_inventory_cached(true);
    json_response(['ok'=>true,'count'=>count($cache['data']),'ageSec'=>0]);
} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}
