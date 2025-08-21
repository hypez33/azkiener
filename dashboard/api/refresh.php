<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$_GET['force'] = '1';
require __DIR__ . '/vehicles.php';
