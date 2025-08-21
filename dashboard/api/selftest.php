
<?php
header('Content-Type: application/json; charset=utf-8');
$user = getenv('MOBILE_USER') ? "***set***" : null;
$pass = getenv('MOBILE_PASSWORD') ? "***set***" : null;
$cust = getenv('CUSTOMER_NUMBERS') ?: null;
$report = [
    "env" => ["MOBILE_USER"=>$user, "MOBILE_PASSWORD"=>$pass, "CUSTOMER_NUMBERS"=>$cust],
    "tmp_is_writable" => is_writable(sys_get_temp_dir() ?: '/tmp'),
    "php_version" => PHP_VERSION,
    "extensions" => ["curl"=>extension_loaded('curl'), "openssl"=>extension_loaded('openssl')],
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
