<?php
// /lib/utils.php
declare(strict_types=1);

/**
 * Lädt die Konfiguration aus config.php.
 * @return array Die Konfiguration.
 */
function cfg(): array {
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
    }
    return $config;
}

/**
 * Stellt sicher, dass das Cache-Verzeichnis existiert.
 */
function ensure_storage(): void {
    $storageDir = dirname(cfg()['CACHE_FILE']);
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0777, true);
    }
}

/**
 * Sendet eine JSON-Antwort und beendet das Skript.
 * @param mixed $data Die zu sendenden Daten.
 * @param int $code Der HTTP-Statuscode.
 */
function json_response($data, int $code = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Führt eine authentifizierte GET-Anfrage an die mobile.de API durch.
 * @param string $url Die URL des Endpunkts.
 * @param array $query Optionale Query-Parameter.
 * @return array Die decodierte JSON-Antwort.
 * @throws Exception bei HTTP- oder JSON-Fehlern.
 */
function http_basic_get_json(string $url, array $query = []): array {
    $c = cfg();
    if (!empty($query)) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $c['MOBILE_USER'] . ':' . $c['MOBILE_PASSWORD'],
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.de.mobile.api+json',
            'Accept-Encoding: gzip',
            'User-Agent: AzkienerDashboard/1.2 (Vercel)'
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code >= 400 || $err) {
        throw new Exception("HTTP error ($code): " . ($err ?: 'Failed to fetch data from mobile.de API'));
    }
    $json = json_decode($body, true);
    if ($json === null) {
        throw new Exception("JSON decode error: " . json_last_error_msg());
    }
    return $json;
}

/**
 * Extrahiert die Inserate-Liste aus der API-Antwort.
 * @param array $root Die JSON-Antwort.
 * @return array Die Liste der Inserate.
 */
function extract_ads(array $root): array {
    return $root['searchResult']['ads'] ?? $root['ads'] ?? [];
}

/**
 * Extrahiert die beste Bild-URL aus einem Inserat-Detailobjekt.
 * @param array $ad Das Inserat-Objekt.
 * @return string|null Die gefundene URL oder null.
 */
function image_from_detail(array $ad): ?string {
    $pick_variant = function (array $arr): ?string {
        foreach (['xxxl', 'xxl', 'xl', 'l', 'm', 's', 'url'] as $k) {
            if (!empty($arr[$k]) && is_string($arr[$k]) && str_starts_with($arr[$k], 'http')) return $arr[$k];
        }
        if (isset($arr['representation']) && is_array($arr['representation'])) {
            $order = ['XXXL', 'XXL', 'XL', 'L', 'M', 'S'];
            $reps = array_column($arr['representation'], 'url', 'size');
            foreach ($order as $size) if (!empty($reps[$size])) return $reps[$size];
        }
        return null;
    };
    if (!empty($ad['images']) && is_array($ad['images'])) {
        foreach ($ad['images'] as $item) {
            if (is_array($item) && ($u = $pick_variant($item))) return $u;
            if (is_string($item) && str_starts_with($item, 'http')) return $item;
        }
    }
    return null;
}

/**
 * Normalisiert ein Inserat in das vom Frontend erwartete Format.
 * @param array $ad Das Roh-Inserat von der API.
 * @return array Das normalisierte Inserat.
 */
function normalize_ad(array $ad): array {
    $priceAmount = $ad['price']['consumerPriceGross'] ?? $ad['price']['consumerPrice']['amount'] ?? 0.0;
    $km = (int)($ad['mileageInKm'] ?? $ad['mileage'] ?? 0);
    $fuel = $ad['fuel'] ?? "";
    $gearbox = strtolower($ad['gearbox'] ?? $ad['transmission'] ?? '');
    $gearLabel = (str_contains($gearbox, 'auto') || str_contains($gearbox, 'dsg')) ? 'Automatic_gear' : 'Manual_gear';
    preg_match('/^(\d{4})/', $ad['firstRegistration'] ?? '', $matches);
    $year = (int)($matches[1] ?? 0);
    return [
        'adId'       => (string)($ad['mobileAdId'] ?? $ad['id'] ?? null),
        'url'        => $ad['detailPageUrl'] ?? '#',
        'title'      => $ad['title'] ?? trim(($ad['make'] ?? '') . ' ' . ($ad['model'] ?? '')),
        'price'      => (int)$priceAmount,
        'priceLabel' => $priceAmount > 0 ? number_format($priceAmount, 0, ',', '.') . ' €' : 'Preis auf Anfrage',
        'specs'      => number_format($km, 0, ',', '.') . ' km · ' . $fuel . ' · ' . $gearLabel,
        'fuel'       => $fuel,
        'km'         => $km,
        'year'       => $year,
        'img'        => '',
    ];
}

/**
 * Ruft die Fahrzeugliste von der API ab.
 * @return array Die Liste der normalisierten Fahrzeuge.
 * @throws Exception bei API-Fehlern.
 */
function fetch_inventory(): array {
    $c = cfg();
    $params = [
        'customerNumber' => $c['CUSTOMER_NUMBERS'],
        'page.size'      => 100,
        'sort.field'     => 'modificationTime',
        'sort.order'     => 'DESCENDING',
        'country'        => 'DE',
    ];
    $items = [];
    $limit = $c['VEHICLE_LIMIT'];
    $data = http_basic_get_json($c['API_BASE_URL'] . $c['API_SEARCH_PATH'], $params);
    $ads  = extract_ads($data);
    foreach ($ads as $ad) {
        if (count($items) >= $limit) break;
        $items[] = normalize_ad($ad);
    }
    return $items;
}

/**
 * Reichet eine Liste von Fahrzeugen mit Bildern aus der Detail-API an.
 * @param array $items Die Fahrzeugliste (wird per Referenz modifiziert).
 */
function enrich_images(array &$items): void {
    $c = cfg();
    $count = 0;
    foreach ($items as &$v) {
        if ($count >= $c['DETAIL_ENRICH_LIMIT'] || empty($v['adId'])) continue;
        try {
            $url = $c['API_BASE_URL'] . str_replace('{adKey}', urlencode($v['adId']), $c['API_DETAIL_PATH']);
            $detail = http_basic_get_json($url);
            if ($img = image_from_detail($detail)) {
                $v['img'] = 'img.php?u=' . urlencode($img);
            }
        } catch (Exception) { /* Ignoriere Fehler bei einzelnen Abrufen */ }
        $count++;
    }
}

/**
 * Holt die Fahrzeugliste aus dem Cache oder von der API.
 * @param bool $force Wenn true, wird der Cache ignoriert.
 * @return array Die Daten im Format {ts, data}.
 */
function get_inventory_cached(bool $force = false): array {
    ensure_storage();
    $c = cfg();
    $cacheFile = $c['CACHE_FILE'];
    if (!$force && file_exists($cacheFile)) {
        $cache = json_decode(@file_get_contents($cacheFile), true);
        if ($cache && (time() - $cache['ts']) < $c['CACHE_TTL_SECONDS']) {
            return $cache;
        }
    }
    try {
        $items = fetch_inventory();
        enrich_images($items);
        $payload = ['ts' => time(), 'data' => $items];
        @file_put_contents($cacheFile, json_encode($payload), LOCK_EX);
        return $payload;
    } catch (Exception $e) {
        // Bei Fehler: veralteten Cache zurückgeben, falls vorhanden
        if (isset($cache) && !empty($cache['data'])) return $cache;
        return ['ts' => time(), 'data' => [], 'error' => $e->getMessage()];
    }
}
