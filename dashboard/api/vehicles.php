<?php
require __DIR__ . '/../lib/utils.php';

try {
    $cache = get_inventory_cached(false);
    json_response($cache['data']);
} catch (Exception $e) {
    json_response(['error' => $e->getMessage()], 500);
}
