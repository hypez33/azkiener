<?php
declare(strict_types=1);

// IMPORTANT: Save without BOM and no whitespace before <?php.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Internally call vehicles with force=1 without emitting any output before headers.
$_GET['force'] = '1';
require __DIR__ . '/vehicles.php';
