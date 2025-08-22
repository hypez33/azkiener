<?php
// lib/utils.php — Kernlogik, HTTP, Normalisierung, Caching
// Variante ohne Rewrite: Bild-Proxy als 'img.php?u=...'

function cfg() {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function ensure_storage() {
    $c = cfg();
    if (!is_dir($c['STORAGE'])) @mkdir($c['STORAGE'], 0775, true);
    if (!is_dir($c['IMG_CACHE_DIR'])) @mkdir($c['IMG_CACHE_DIR'], 0775, true);
    if (!file_exists($c['CACHE_FILE'])) file_put_contents($c['CACHE_FILE'], json_encode(['ts'=>0,'data'=>[]]));
}

function http_get_json($url, $headers = [], $query = []) {
    if (!empty($query)) {
        $qs = http_build_query($query);
        $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => cfg()['HTTP_TIMEOUT'],
        CURLOPT_TIMEOUT        => cfg()['HTTP_TIMEOUT'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception("HTTP error: $err");
    }
    curl_close($ch);
    if ($code >= 400) {
        throw new Exception("HTTP $code: ".substr($body,0,400));
    }
    $json = json_decode($body, true);
    if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error: ".json_last_error_msg());
    }
    return $json;
}

function auth_headers() {
    $c = cfg();
    $auth = base64_encode($c['MOBILE_USER'].':'.$c['MOBILE_PASSWORD']);
    return [
        'Authorization: Basic '.$auth,
        'Accept: '.$c['ACCEPT'],
        'User-Agent: ak-mobile-php/1.0'
    ];
}

function extract_ads($root) {
    if (is_array($root)) {
        if (array_is_list($root)) {
            return $root;
        } else {
            if (isset($root['ads']) && is_array($root['ads'])) return $root['ads'];
            if (isset($root['searchResult']['ads']) && is_array($root['searchResult']['ads'])) return $root['searchResult']['ads'];
            foreach ($root as $k=>$v) {
                if (is_string($k) && strtolower($k)==='ads' && is_array($v)) return $v;
            }
        }
    }
    return [];
}

function pick_variant_url($arr) {
    foreach (['xxxl','xxl','xl','l','m','s','icon','url','href','src','originalUrl'] as $k) {
        if (isset($arr[$k]) && is_string($arr[$k]) && preg_match('~^https?://~i', $arr[$k])) {
            return $arr[$k];
        }
    }
    return '';
}

function image_from_detail($ad) {
    if (isset($ad['images']) && is_array($ad['images']) && array_is_list($ad['images'])) {
        foreach ($ad['images'] as $item) {
            if (is_array($item)) {
                $u = pick_variant_url($item);
                if ($u) return $u;
            } elseif (is_string($item) && preg_match('~^https?://~i', $item)) {
                return $item;
            }
        }
    }
    if (isset($ad['images']) && is_array($ad['images']) && !array_is_list($ad['images'])) {
        $lst = $ad['images']['images'] ?? ($ad['images']['image'] ?? []);
        if (!is_array($lst)) $lst = [$lst];
        foreach ($lst as $item) {
            if (is_array($item)) {
                $u = pick_variant_url($item);
                if ($u) return $u;
            } elseif (is_string($item) && preg_match('~^https?://~i', $item)) {
                return $item;
            }
        }
    }
    if (isset($ad['media']) && is_array($ad['media'])) {
        foreach (['images','image','thumbnails','representations'] as $key) {
            $arr = $ad['media'][$key] ?? null;
            if (!$arr) continue;
            if (!is_array($arr)) $arr = [$arr];
            foreach ($arr as $item) {
                if (is_array($item)) {
                    $u = pick_variant_url($item);
                    if ($u) return $u;
                } elseif (is_string($item) && preg_match('~^https?://~i', $item)) {
                    return $item;
                }
            }
        }
    }
    if (isset($ad['resources']['images']) && is_array($ad['resources']['images'])) {
        $arr = $ad['resources']['images'];
        if (!is_array($arr)) $arr = [$arr];
        foreach ($arr as $item) {
            if (is_array($item)) {
                $u = pick_variant_url($item);
                if ($u) return $u;
            }
        }
    }
    foreach (['imageUrl','thumbnailUrl','thumbUrl','pictureUrl','photoUrl'] as $k) {
        if (!empty($ad[$k]) && preg_match('~^https?://~i', $ad[$k])) return $ad[$k];
    }
    $stack = [$ad]; $depth = 0;
    while ($stack && $depth < 6) {
        $next = [];
        foreach ($stack as $node) {
            if (is_array($node)) {
                foreach ($node as $k=>$v) {
                    if (is_string($v) && preg_match('~^https?://~i', $v) && preg_match('~(img|image|photo|thumb|media|repres)~i', (string)$k)) {
                        return $v;
                    }
                    if (is_array($v)) $next[] = $v;
                }
            }
        }
        $stack = $next; $depth++;
    }
    return '';
}

function nf_eur($val) {
    return number_format((int)$val, 0, ',', '.') . ' €';
}

function parse_price_value($price) {
    // mobile.de kann verschiedene Formen liefern; wir probieren mehrere:
    // Beispiele:
    // 1) price: { gross: 19900 }
    // 2) price: { gross: { amount: 19900 } }
    // 3) price: { amount: 19900 }
    // 4) price: 19900
    // 5) price: { consumerPriceGross: 19900 } (Fallback)
    if (!is_array($price)) {
        if (is_numeric($price)) return (int)$price;
        return 0;
    }
    if (isset($price['gross'])) {
        if (is_numeric($price['gross'])) return (int)$price['gross'];
        if (is_array($price['gross']) && isset($price['gross']['amount']) && is_numeric($price['gross']['amount']))
            return (int)$price['gross']['amount'];
    }
    if (isset($price['amount']) && is_numeric($price['amount'])) return (int)$price['amount'];
    if (isset($price['consumerPriceGross']) && is_numeric($price['consumerPriceGross'])) return (int)$price['consumerPriceGross'];
    // weitere Fallbacks
    foreach (['value','total','brutto','net'] as $k) {
        if (isset($price[$k]) && is_numeric($price[$k])) return (int)$price[$k];
    }
    return 0;
}

function normalize_ad($ad) {
    if (isset($ad['ad']) && is_array($ad['ad'])) $ad = $ad['ad'];

    $adId      = $ad['mobileAdId'] ?? ($ad['id'] ?? ($ad['adKey'] ?? null));
    $detailUrl = $ad['detailPageUrl'] ?? ($ad['adUrl'] ?? ($ad['url'] ?? '#'));

    $specifics = $ad['specifics'] ?? [];
    $tech      = $specifics['technical'] ?? [];

    $make   = $ad['make'] ?? '';
    $model  = $ad['model'] ?? '';
    $variant= $ad['modelDescription'] ?? ($ad['variant'] ?? '');
    $title  = trim(implode(' ', array_filter([$make,$model,$variant]))) ?: ($ad['title'] ?? 'Fahrzeug');

    $mileage = $specifics['mileage'] ?? ($ad['mileage'] ?? 0);
    $firstReg= $specifics['firstRegistrationDate'] ?? ($ad['firstRegistrationDate'] ?? null);
    $year    = $firstReg ? intval(substr((string)$firstReg,0,4)) : 0;

    $fuel    = $tech['fuel'] ?? ($ad['fuel'] ?? '');
    $gearbox = $tech['gearbox'] ?? ($ad['gearbox'] ?? '');
    $powerKW = $tech['power'] ?? ($ad['powerKW'] ?? null);

    $specs = [];
    if ($year) $specs[] = (string)$year;
    if ($mileage) $specs[] = number_format((int)$mileage, 0, ',', '.') . ' km';
    if ($fuel) $specs[] = ucfirst(strtolower((string)$fuel));
    if ($gearbox) $specs[] = ucfirst(strtolower((string)$gearbox));
    if ($powerKW) {
        $kw = floatval($powerKW); $ps = intval(round($kw * 1.35962));
        $specs[] = intval($kw) . " kW ($ps PS)";
    }

    $priceBlock = $ad['price'] ?? [];
    $pval = parse_price_value($priceBlock);
    $plabel = $pval > 0 ? nf_eur($pval) : 'Preis auf Anfrage';

    return [
        'adId'       => $adId,
        'url'        => $detailUrl,
        'title'      => $title,
        'price'      => $pval,
        'priceLabel' => $plabel,
        'specs'      => implode(' · ', $specs),
        'fuel'       => $fuel ? ucfirst(strtolower($fuel)) : '',
        'km'         => $mileage ? (int)$mileage : 0,
        'year'       => $year,
        'img'        => '',
    ];
}

function fetch_inventory() {
    $c = cfg();
    $params = [
        'customerNumber' => $c['CUSTOMER_NUMBERS'],
        'page.size'      => '100',
        'sort.field'     => 'modificationTime',
        'sort.order'     => 'DESCENDING',
        'country'        => 'DE',
    ];
    $data = http_get_json($c['BASE_URL'], auth_headers(), $params);
    $ads  = extract_ads($data);

    $out = [];
    foreach ($ads as $ad) {
        if (isset($ad['ad']) && is_array($ad['ad'])) $ad = $ad['ad'];
        $out[] = normalize_ad($ad);
    }
    return $out;
}

function fetch_ad_detail($adKey) {
    $c = cfg();
    $url = str_replace('{adKey}', urlencode($adKey), $c['DETAIL_URL']);
    return http_get_json($url, auth_headers());
}

function enrich_images(&$items) {
    $c = cfg();
    if (!$c['DETAIL_ENRICH']) return;

    $count = 0;
    foreach ($items as &$v) {
        if (!empty($v['img'])) continue;
        $adId = $v['adId'] ?? null;
        if (!$adId) continue;

        try {
            $detail = fetch_ad_detail($adId);
            $ad = isset($detail['ad']) && is_array($detail['ad']) ? $detail['ad'] : $detail;
            $img = image_from_detail($ad);
            if ($img) {
                // *** Ohne Rewrite: IMMER 'img.php?u=' nutzen ***
                if ($c['PROXY_IMAGES'] && preg_match('~^https?://~i', $img)) {
                    $v['img'] = 'img.php?u=' . urlencode($img); // KEIN führender Slash!
                } else {
                    $v['img'] = $img;
                }
            }
        } catch (Exception $e) {
            // still weiter
        }

        $count++;
        if ($count >= $c['DETAIL_LIMIT']) break;
    }
}

function read_cache() {
    $c = cfg();
    if (!file_exists($c['CACHE_FILE'])) return ['ts'=>0,'data'=>[]];
    $raw = file_get_contents($c['CACHE_FILE']);
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['ts'=>0,'data'=>[]];
}

function write_cache($data) {
    $c = cfg();
    $payload = ['ts'=>time(),'data'=>$data];
    file_put_contents($c['CACHE_FILE'], json_encode($payload, JSON_UNESCAPED_SLASHES));
    return $payload;
}

function get_inventory_cached($force=false) {
    ensure_storage();
    $c = cfg();
    $cache = read_cache();
    $age = time() - ($cache['ts'] ?? 0);

    if (!$force && $age < $c['CACHE_TTL_SECONDS'] && !empty($cache['data'])) {
        return $cache;
    }
    $items = fetch_inventory();
    enrich_images($items);
    return write_cache($items);
}

function json_response($data, $code=200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
