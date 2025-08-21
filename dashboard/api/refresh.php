<?php
// dashboard/api/refresh.php
// Forces a data refresh using available utils function names.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$meta = ["steps" => []];
try {
    $cfgPath = __DIR__ . '/../lib/config.php';
    if (file_exists($cfgPath)) { require_once $cfgPath; $meta["steps"][] = "config_loaded"; }
    $utilsPath = __DIR__ . '/../lib/utils.php';
    if (file_exists($utilsPath)) { require_once $utilsPath; $meta["steps"][] = "utils_loaded"; }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "boot_failed", "error" => $e->getMessage()]);
    exit;
}

$fns_try = [
    'refreshVehicles',
    'updateVehicles',
    'fetchVehicles',
    'loadVehicles',
    'getVehicles',
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

if ($data === null) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "no_refresh_function_found", "steps" => $meta["steps"]]);
    exit;
}

echo json_encode(["status" => "ok", "refreshed" => true, "used" => $used, "count" => is_array($data) ? count($data) : null]);
