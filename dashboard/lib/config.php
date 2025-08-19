<?php
// lib/config.php — Konfiguration

return [
    // >> TRAGE HIER EURE ZUGANGSDATEN EIN <<
    'MOBILE_USER'        => 'dlr_andrekiener',
    'MOBILE_PASSWORD'    => 'T7zaurBoCaXZ',
    'CUSTOMER_NUMBERS'   => '752427',      // mehrere per Komma möglich

    // Caching / Verhalten
    'CACHE_TTL_SECONDS'  => 10 * 60,       // 10 Minuten
    'DETAIL_ENRICH'      => true,          // Bilder via Detail-Abruf laden
    'DETAIL_LIMIT'       => 200,           // max. Detail-Calls pro Refresh (Schutz)
    'HTTP_TIMEOUT'       => 25,            // Sekunden Timeout je Request

    // Basis
    'BASE_URL'           => 'https://services.mobile.de/search-api/search',
    'DETAIL_URL'         => 'https://services.mobile.de/search-api/ad/{adKey}',
    'ACCEPT'             => 'application/vnd.de.mobile.api+json',

    // Pfade
    'ROOT'               => dirname(__DIR__),
    'STORAGE'            => dirname(__DIR__, 1) . '/../storage',
    'CACHE_FILE'         => dirname(__DIR__, 1) . '/../storage/cache.json',
    'IMG_CACHE_DIR'      => dirname(__DIR__, 1) . '/../storage/img',

    // Proxy
    'PROXY_IMAGES'       => true,          // /img?u=… im Frontend verwenden
];
