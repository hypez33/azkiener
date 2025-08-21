<?php
// dashboard/api/cache.php - ephemeral cache
function cache_dir() {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_cache';
    if (!file_exists($dir)) { @mkdir($dir, 0777, true); }
    return $dir;
}
function cache_path($key) {
    return cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
}
function cache_get($key, $ttl_seconds = 300) {
    $path = cache_path($key);
    if (!file_exists($path)) return false;
    $age = time() - filemtime($path);
    if ($age > $ttl_seconds) return false;
    $json = @file_get_contents($path);
    if ($json === false) return false;
    $data = json_decode($json, true);
    return $data;
}
function cache_set($key, $value) {
    $path = cache_path($key);
    @file_put_contents($path, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
