<?php
declare(strict_types=1);

// IMPORTANT: Save without BOM and no whitespace before <?php.

header('Content-Type: application/json; charset=utf-8');

$report = [
    "env" => [
        "MOBILE_USER" => getenv('MOBILE_USER') ? "***set***" : null,
        "MOBILE_PASSWORD" => getenv('MOBILE_PASSWORD') ? "***set***" : null,
        "CUSTOMER_NUMBERS" => getenv('CUSTOMER_NUMBERS') ?: null,
    ],
    "tmp_is_writable" => is_writable(sys_get_temp_dir() ?: '/tmp'),
    "php_version" => PHP_VERSION,
    "extensions" => [
        "curl" => extension_loaded('curl'),
        "openssl" => extension_loaded('openssl'),
    ],
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
