<?php
// dashboard/api/selftest.php
// Run-time diagnostics for Vercel Serverless PHP

header('Content-Type: application/json; charset=utf-8');
$report = [
    "time" => gmdate('c'),
    "php_version" => PHP_VERSION,
    "sapi" => php_sapi_name(),
    "tmp_is_writable" => is_writable(sys_get_temp_dir() ?: '/tmp'),
    "env" => [
        "MOBILE_USER" => getenv('MOBILE_USER') ? "***set***" : null,
        "MOBILE_PASSWORD" => getenv('MOBILE_PASSWORD') ? "***set***" : null,
        "CUSTOMER_NUMBERS" => getenv('CUSTOMER_NUMBERS') ?: null,
    ],
    "extensions" => [
        "curl" => extension_loaded('curl'),
        "json" => extension_loaded('json'),
        "mbstring" => extension_loaded('mbstring'),
        "openssl" => extension_loaded('openssl'),
    ],
    "network" => [],
];

// Optional: target URL from env (if your vehicles.php hits an upstream proxy/API)
$target = getenv('VEHICLES_API_URL');
if ($target) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $target,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $report["network"]["upstream_head"] = [
        "url" => $target,
        "http_code" => $code,
        "error" => $err ?: null,
    ];
} else {
    $report["network"]["note"] = "VEHICLES_API_URL not set (vehicles.php may return demo payload).";
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
