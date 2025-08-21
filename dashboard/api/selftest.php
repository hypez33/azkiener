<?php
declare(strict_types=1);
ob_start(); // capture all output to prevent stray bytes
ini_set('display_errors', '0');
error_reporting(E_ALL);

$__errors = [];
set_error_handler(function($severity, $message, $file, $line) use (&$__errors) {
    // Convert warnings/notices to collected errors, don't echo
    $__errors[] = ["severity"=>$severity, "message"=>$message, "file"=>$file, "line"=>$line];
    return true;
});
register_shutdown_function(function() use (&$__errors) {
    $last = error_get_last();
    if ($last !== null) {
        // Fatal error occurred; emit JSON
        while (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(["error"=>"fatal", "details"=>$last, "hint"=>"Check PHP syntax / runtime."], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

function finish_json($payload, int $status=200, array $errors=[]) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($status);
    if (!empty($errors)) $payload["_errors"] = $errors;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$report = [
    "env" => [
        "MOBILE_USER" => getenv('MOBILE_USER') ? "***set***" : null,
        "MOBILE_PASSWORD" => getenv('MOBILE_PASSWORD') ? "***set***" : null,
        "CUSTOMER_NUMBERS" => getenv('CUSTOMER_NUMBERS') ?: null
    ],
    "tmp_is_writable" => is_writable(sys_get_temp_dir() ?: '/tmp'),
    "php_version" => PHP_VERSION,
    "extensions" => ["curl"=>extension_loaded('curl'), "openssl"=>extension_loaded('openssl')]
];
finish_json($report, 200, $__errors);
