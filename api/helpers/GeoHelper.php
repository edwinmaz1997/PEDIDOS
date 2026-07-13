<?php
/**
 * GeoHelper — utilidades de geolocalización sin API de pago.
 * Resuelve links de Google Maps (incluyendo links cortos maps.app.goo.gl)
 * y calcula distancias con la fórmula de Haversine.
 */
class GeoHelper {

    /**
     * Extrae lat,lng de una URL de Google Maps.
     * Soporta:
     *  - https://www.google.com/maps?q=14.123,-91.456
     *  - https://maps.google.com/?q=14.123,-91.456
     *  - https://www.google.com/maps/@14.123,-91.456,15z
     *  - https://www.google.com/maps/place/.../@14.123,-91.456,17z
     *  - https://maps.app.goo.gl/XXXXX (link corto — requiere resolver redirección)
     *
     * @return array|null ['lat' => float, 'lng' => float] o null si no se pudo extraer
     */
    public static function extractCoords(?string $url): ?array {
        if (!$url) return null;
        $url = trim($url);
        if (!$url) return null;

        // Si es un link corto, resolver la redirección primero
        if (stripos($url, 'goo.gl') !== false || stripos($url, 'maps.app') !== false) {
            $resolved = self::resolveShortUrl($url);
            if ($resolved) $url = $resolved;
        }

        // Patrón 1: ?q=lat,lng  o  &q=lat,lng
        if (preg_match('/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }

        // Patrón 2: @lat,lng,zoom  (formato típico de Google Maps al compartir un lugar)
        if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $url, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }

        // Patrón 3: !3dLAT!4dLNG (formato interno de algunos links de "place")
        if (preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $url, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }

        // Patrón 4: coordenadas plain text "lat,lng"
        if (preg_match('/^\s*(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)\s*$/', $url, $m)) {
            return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
        }

        return null;
    }

    /**
     * Sigue la redirección de un link corto de Google Maps (maps.app.goo.gl)
     * y devuelve la URL final expandida.
     */
    public static function resolveShortUrlPublic(string $shortUrl): ?string {
        return self::resolveShortUrl($shortUrl);
    }

    private static function resolveShortUrl(string $shortUrl): ?string {
        if (!filter_var($shortUrl, FILTER_VALIDATE_URL)) return null;

        // First try HEAD request to get redirect location
        $ch = curl_init($shortUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: es-GT,es;q=0.9,en;q=0.8',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
        ]);

        $body     = curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($finalUrl && $finalUrl !== $shortUrl) {
            // Si la URL final ya tiene coordenadas, usarla directamente
            if (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $finalUrl) ||
                preg_match('/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/', $finalUrl) ||
                preg_match('/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', $finalUrl)) {
                return $finalUrl;
            }
        }

        // Buscar coordenadas en el body del HTML (Google Maps embed patterns)
        if ($body) {
            // Patrón !3dLAT!4dLNG en el HTML
            if (preg_match('/!3d(-?\d+\.\d{4,})!4d(-?\d+\.\d{4,})/', $body, $m)) {
                return 'https://www.google.com/maps?q=' . $m[1] . ',' . $m[2];
            }
            // Patrón @LAT,LNG en el HTML
            if (preg_match('/@(-?\d+\.\d{4,}),(-?\d+\.\d{4,})/', $body, $m)) {
                return 'https://www.google.com/maps?q=' . $m[1] . ',' . $m[2];
            }
            // Patrón "center":"LAT,LNG" en JSON del HTML
            if (preg_match('/"center":"(-?\d+\.\d+),(-?\d+\.\d+)"/', $body, $m)) {
                return 'https://www.google.com/maps?q=' . $m[1] . ',' . $m[2];
            }
            // meta refresh
            if (preg_match('/content="0;url=([^"]+)"/i', $body, $m)) {
                return html_entity_decode($m[1]);
            }
        }

        return $finalUrl ?: null;
    }

    /**
     * Calcula la distancia en kilómetros entre dos puntos (fórmula de Haversine).
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Calcula la tarifa de envío según la distancia en km.
     * Tabla de tarifas:
     *   0    - 4   km => Q15
     *   4.01 - 6   km => Q20
     *   6.01 - 8   km => Q25
     *   8.01 - 10  km => Q30
     *   10.01 - 12 km => Q35
     *   > 12 km       => fuera de cobertura (null)
     *
     * @return float|null tarifa en Q, o null si está fuera de cobertura
     */
    public static function feeForDistance(float $km): ?float {
        if ($km <= 4)    return 15.00;
        if ($km <= 6)    return 20.00;
        if ($km <= 8)    return 25.00;
        if ($km <= 10)   return 30.00;
        if ($km <= 12)   return 35.00;
        if ($km <= 14)   return 40.00;
        return null; // fuera de cobertura
    }
}
