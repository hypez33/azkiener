<?php
declare(strict_types=1);
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  "ok" => true,
  "service" => "azkiener-api",
  "runtime" => "php",
  "ts" => time()
], JSON_UNESCAPED_SLASHES);
