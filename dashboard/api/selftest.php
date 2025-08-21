<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    "env" => [
        "MOBILE_USER" => getenv('MOBILE_USER') ? "***set***" : null,
        "MOBILE_PASSWORD" => getenv('MOBILE_PASSWORD') ? "***set***" : null,
        "CUSTOMER_NUMBERS" => getenv('CUSTOMER_NUMBERS') ?: null
    ],
    "tmp_is_writable" => is_writable(sys_get_temp_dir() ?: '/tmp'),
    "php_version" => PHP_VERSION,
    "extensions" => ["curl"=>extension_loaded('curl'), "openssl"=>extension_loaded('openssl')]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
