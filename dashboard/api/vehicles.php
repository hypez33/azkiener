<?php
// /api/vehicles.php
// Ruft Fahrzeugdaten ab und verwendet dabei standardmäßig den Cache.
declare(strict_types=1);

require __DIR__ . '/../lib/utils.php';

// Prüfen, ob die Zugangsdaten konfiguriert sind.
if (empty(cfg()['MOBILE_USER'])) {
    json_response(['ts' => time(), 'data' => [], 'error' => 'API credentials are not configured.']);
}

$data = get_inventory_cached(false); // false = Cache verwenden, falls gültig
json_response($data);
