<?php
// dashboard/lib/config.php
// Reads credentials from Environment Variables.
// TEMPORARY FALLBACKS are included because your Vercel runtime isn't seeing the env vars yet.
// Replace/remove the hardcoded fallbacks once env vars are confirmed working.

// Helper: clean string or null
function env_or_null($key) {
    $v = getenv($key);
    if ($v === false || $v === '') return null;
    return trim($v);
}

$CONFIG = [
    'MOBILE_USER'      => env_or_null('MOBILE_USER')      ?: 'dlr_andrekiener', // Fallback (remove later)
    'MOBILE_PASSWORD'  => env_or_null('MOBILE_PASSWORD')  ?: 'T7zaurBoCaXZ',    // Fallback (remove later)
    'CUSTOMER_NUMBERS' => env_or_null('CUSTOMER_NUMBERS') ?: '752427',          // Fallback (remove later)
];

// Storage/Caching path (ephemeral on Vercel)
$isReadonlyFs = !!getenv('VERCEL') || !!getenv('NOW_REGION');
$tmp = sys_get_temp_dir();
$storageBase = $isReadonlyFs && $tmp ? $tmp . DIRECTORY_SEPARATOR . 'azkiener' : __DIR__ . '/../storage';

$CONFIG['CACHE_FILE']    = $storageBase . DIRECTORY_SEPARATOR . 'vehicles.json';
$CONFIG['IMG_CACHE_DIR'] = $storageBase . DIRECTORY_SEPARATOR . 'img';

if (!file_exists($storageBase)) { @mkdir($storageBase, 0777, true); }
if (!file_exists($CONFIG['IMG_CACHE_DIR'])) { @mkdir($CONFIG['IMG_CACHE_DIR'], 0777, true); }

// Convenience accessors
function cfg($key, $default=null) {
    global $CONFIG;
    return array_key_exists($key, $CONFIG) ? $CONFIG[$key] : $default;
}
