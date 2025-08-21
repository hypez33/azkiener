
<?php
// Forces refresh by calling vehicles with ?force=1
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$qs = $_SERVER['QUERY_STRING'] ?? '';
$sep = $qs ? '&' : '';
$url = '/api/vehicles.php' . ($qs ? ('?' . $qs . $sep . 'force=1') : '?force=1');
// Simple internal redirect
$_SERVER['REQUEST_URI'] = $url;
include __DIR__ . '/vehicles.php';
