<?php
// dashboard/api/vehicles.php
// Serverless-safe vehicles endpoint that does NOT depend on VEHICLES_API_URL.
// It will:
// 1) include ../lib/config.php and ../lib/utils.php
// 2) Try to call one of several fetch/list functions if present
// 3) Else read from $CONFIG['CACHE_FILE']
// 4) Respond with JSON

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: s-maxage=30, stale-while-revalidate=120');

$debug = isset($_GET['debug']);

// Load config/utils if available
$meta = ["steps" => []];
$CONFIG = null;
$cacheFile = null;
try {
    $cfgPath = __DIR__ . '/../lib/config.php';
    if (file_exists($cfgPath)) {
        require_once $cfgPath;
        $meta["steps"][] = "config_loaded";
    } else {
        $meta["steps"][] = "config_missing";
    }
    $utilsPath = __DIR__ . '/../lib/utils.php';
    if (file_exists($utilsPath)) {
        require_once $utilsPath;
        $meta["steps"][] = "utils_loaded";
    } else {
        $meta["steps"][] = "utils_missing";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "boot_failed", "error" => $e->getMessage()]);
    exit;
}

// Resolve cache file from config
if (function_exists('cfg')) {
    $cacheFile = cfg('CACHE_FILE');
} elseif (isset($CONFIG['CACHE_FILE'])) {
    $cacheFile = $CONFIG['CACHE_FILE'];
} else {
    // default to /tmp if nothing else
    $cacheFile = sys_get_temp_dir() . '/azkiener/vehicles.json';
    @mkdir(dirname($cacheFile), 0777, true);
}

// Helper: read cache file
function read_cache_json($path) {
    if (!file_exists($path)) return null;
    $txt = @file_get_contents($path);
    if ($txt === false) return null;
    $json = json_decode($txt, true);
    return $json;
}

// Try to use a utils fetch/list function if available
$fns_try = [
    'fetchVehicles',
    'getVehicles',
    'loadVehicles',
    'vehicles_list',
    'vehiclesList',
    'refreshVehiclesAndGet',
];

$data = null;
$used = null;
foreach ($fns_try as $fn) {
    if (function_exists($fn)) {
        try {
            $data = call_user_func($fn);
            $used = $fn;
            $meta["steps"][] = "used:$fn";
            break;
        } catch (Throwable $e) {
            $meta["steps"][] = "fn_$fn_failed:" . $e->getMessage();
        }
    }
}

// If no function available, try reading cache file
if ($data === null) {
    $data = read_cache_json($cacheFile);
    if ($data !== null) {
        $meta["steps"][] = "read_cache_file";
    }
}

if ($data === null) {
    // As a last resort, return a helpful error
    http_response_code(502);
    $out = [
        "status" => "error",
        "message" => "no_data",
        "hint" => "Stelle sicher, dass lib/utils.php eine Fetch-Funktion bereitstellt oder dass der Cache-Datei Pfad korrekt ist.",
        "cache_file" => $cacheFile,
        "env_seen" => [
            "MOBILE_USER" => getenv('MOBILE_USER') ? "***set***" : null,
            "MOBILE_PASSWORD" => getenv('MOBILE_PASSWORD') ? "***set***" : null,
            "CUSTOMER_NUMBERS" => getenv('CUSTOMER_NUMBERS') ?: null,
        ],
        "steps" => $meta["steps"],
    ];
    if ($debug) $out["debug"] = true;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalize output shape: expects array of vehicles
echo json_encode([
    "status" => "ok",
    "cached" => false,
    "data" => $data,
    "meta" => $debug ? $meta : null
], JSON_UNESCAPED_UNICODE);
