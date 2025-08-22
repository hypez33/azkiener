<?php
// /lib/config.php
declare(strict_types=1);

/**
 * Hilfsfunktion zum sicheren Abrufen von Umgebungsvariablen.
 * @param string $key Name der Umgebungsvariable.
 * @return string|null Wert oder null, falls nicht gesetzt.
 */
function env_or_null(string $key): ?string {
    $v = getenv($key);
    return ($v === false || $v === '') ? null : trim($v);
}

// --- API-Zugangsdaten & Konfiguration ---
// Es wird dringend empfohlen, hierfür Vercel Environment Variables zu verwenden.
// Die hartcodierten Fallbacks dienen der lokalen Entwicklung.
$CONFIG = [
    'MOBILE_USER'      => env_or_null('MOBILE_USER')      ?: 'dlr_andrekiener',
    'MOBILE_PASSWORD'  => env_or_null('MOBILE_PASSWORD')  ?: 'T7zaurBoCaXZ',
    'CUSTOMER_NUMBERS' => env_or_null('CUSTOMER_NUMBERS') ?: '752427',

    // API-Endpunkte für mobile.de
    'API_BASE_URL'     => 'https://services.mobile.de/search-api',
    'API_SEARCH_PATH'  => '/search',
    'API_DETAIL_PATH'  => '/ad/{adKey}',

    // --- Caching-Konfiguration ---
    'CACHE_TTL_SECONDS' => 300, // 5 Minuten Cache-Gültigkeit für Fahrzeugdaten
    'VEHICLE_LIMIT'     => 60,  // Maximale Anzahl der abzurufenden Fahrzeuge

    // --- Bild-Anreicherung ---
    // Das Abrufen von Details für jedes Inserat kann langsam sein.
    // Limitiert, für wie viele Inserate Bilder nachgeladen werden.
    'DETAIL_ENRICH_LIMIT' => 20,
];

// --- Dateisystem-Pfade ---
// Vercel hat ein schreibgeschütztes Dateisystem, außer im /tmp-Verzeichnis.
// Wir erkennen die Vercel-Umgebung und passen den Speicherpfad an.
$isVercel = env_or_null('VERCEL') || env_or_null('NOW_REGION');
$storageBase = $isVercel
    ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'azkiener_cache'
    : __DIR__ . '/../storage';

$CONFIG['CACHE_FILE']    = $storageBase . DIRECTORY_SEPARATOR . 'vehicles.json';
$CONFIG['IMG_CACHE_DIR'] = $storageBase . DIRECTORY_SEPARATOR . 'img_proxy';

return $CONFIG;
