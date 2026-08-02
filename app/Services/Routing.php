<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Cálculo de rutas usando OSRM (servidor público de demostración, sin API key).
 * Si OSRM no responde, cae a una estimación por distancia en línea recta.
 */
class Routing
{
    /**
     * @return array{distance_m:int, duration_s:int, geometry:array<int,array{0:float,1:float}>}
     *         geometry = [[lat,lng], ...]
     */
    public static function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        try {
            $url = "https://router.project-osrm.org/route/v1/driving/"
                 . "{$fromLng},{$fromLat};{$toLng},{$toLat}"
                 . "?overview=full&geometries=geojson";

            $res = Http::timeout(8)->get($url);

            if ($res->ok() && ($res->json('code') === 'Ok')) {
                $r = $res->json('routes.0');
                $coords = array_map(
                    fn ($c) => [round($c[1], 6), round($c[0], 6)], // [lng,lat] -> [lat,lng]
                    $r['geometry']['coordinates'] ?? []
                );
                if (count($coords) >= 2) {
                    return [
                        'distance_m' => (int) round($r['distance']),
                        'duration_s' => (int) round($r['duration']),
                        'geometry'   => $coords,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // cae al fallback
        }

        return self::fallback($fromLat, $fromLng, $toLat, $toLng);
    }

    /** Estimación sin OSRM: distancia haversine * factor de calle, velocidad urbana media. */
    private static function fallback(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $straight = self::haversine($fromLat, $fromLng, $toLat, $toLng);
        $dist = (int) round($straight * 1.35);            // factor de trazado de calles
        $dur  = (int) round(($dist / 1000) / 24 * 3600);  // ~24 km/h promedio urbano

        return [
            'distance_m' => $dist,
            'duration_s' => max($dur, 60),
            'geometry'   => [[$fromLat, $fromLng], [$toLat, $toLng]],
        ];
    }

    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
           + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
