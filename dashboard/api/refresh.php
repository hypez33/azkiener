<?php
// /api/refresh.php
// Erzwingt das Neuladen der Fahrzeugdaten von der API und umgeht den Cache.
declare(strict_types=1);

require __DIR__ . '/../lib/utils.php';

// Prüfen, ob die Zugangsdaten konfiguriert sind.
if (empty(cfg()['MOBILE_USER'])) {
    json_response(['ts' => time(), 'data' => [], 'error' => 'API credentials are not configured.']);
}

// Das 'true' als Argument erzwingt die Aktualisierung.
$data = get_inventory_cached(true);
json_response($data);
