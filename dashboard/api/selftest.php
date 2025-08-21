<?php
declare(strict_types=1);
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

$__errors = [];
set_error_handler(function($severity, $message, $file, $line) use (&$__errors) {
    $__errors[] =(["severity"=>$severity, "message"=>$message, "file"=>$file, "line"=>$line]);
    return true;
});
register_shutdown_function(function() use (&$__errors) {
    $last = error_get_last();
    if ($last !== null) {
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(["error"=>"fatal","details"=>$last], JSON_UNESCAPED_UNICODE);
        exit;
    }
});
function finish_json($payload, int $status=200) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getenv('MOBILE_USER') ? "***set***" : null;
$pass = getenv('MOBILE_PASSWORD') ? "***set***" : null;
$cust = getenv('CUSTOMER_NUMBERS') ?: null;
finish_json([
    "env" => ["MOBILE_USER"=>$user, "MOBILE_PASSWORD"=>$pass, "CUSTOMER_NUMBERS"=>$cust],
    "tmp_is_writable" => is_writable(sys_get_temp_dir() ?: '/tmp'),
    "php_version" => PHP_VERSION,
    "extensions" => ["curl"=>extension_loaded('curl'), "openssl"=>extension_loaded('openssl')]
], 200);
