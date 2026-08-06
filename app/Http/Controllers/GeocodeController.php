<?php

namespace App\Http\Controllers;

use App\Models\CustomPlace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Proxy de geocodificación (Hito 4 — Opción 1). Prefiere Google Maps Geocoding
 * (mejor cobertura de calles en Perú); si no hay clave o Google falla, devuelve
 * null y el frontend usa su respaldo con Nominatim (OSM). La clave vive solo en
 * el servidor (config), nunca en el navegador. Cachea para minimizar llamadas/costo.
 */
class GeocodeController extends Controller
{
    private function key(): ?string
    {
        return config('services.google_maps.key');
    }

    /** Google disponible = hay clave y no está en enfriamiento tras un fallo reciente. */
    private function googleEnabled(): bool
    {
        return $this->key() && ! Cache::has('geo:google_down');
    }

    /** Tras un fallo (p. ej. billing no activo), evitar llamar a Google por unos minutos. */
    private function markGoogleDown(): void
    {
        Cache::put('geo:google_down', true, now()->addMinutes(5));
    }

    /** Coordenadas -> nombre del lugar. */
    public function reverse(Request $request)
    {
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');
        if (! $lat || ! $lng) {
            return response()->json(['label' => null]);
        }

        // 0) ZONAS LOCALES de MajesGo: si el punto cae dentro de una zona propia, usar su nombre
        //    (aunque Google/OSM no la tengan). Es lo que el negocio conoce localmente.
        $zone = $this->customPlaceAt($lat, $lng);
        if ($zone !== null) {
            return response()->json(['label' => $zone, 'source' => 'zona']);
        }

        // cache por coords redondeadas (~11 m) durante 30 días: mismos puntos no se re-consultan
        $ck = 'geo:rev:' . round($lat, 4) . ',' . round($lng, 4);
        $label = Cache::remember($ck, now()->addDays(30), fn () => $this->googleReverse($lat, $lng));

        if ($label === null) {
            Cache::forget($ck); // no cachear el fallo, para reintentar cuando Google se active
        }

        return response()->json(['label' => $label]);
    }

    /** Texto -> lugares (para el buscador de destino). */
    public function search(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json(['results' => null]);
        }

        // 0) ZONAS LOCALES de MajesGo primero (aparecen arriba en las sugerencias)
        $custom = $this->customPlaceSearch($q);

        $ck = 'geo:sea:' . mb_strtolower($q);
        $google = Cache::remember($ck, now()->addDays(7), fn () => $this->googleSearch($q));
        if ($google === null) {
            Cache::forget($ck);
        }

        $results = array_merge($custom, $google ?? []);
        // si no hay ni zonas locales ni Google, devolver null para que el frontend use Nominatim
        if (! $results) {
            return response()->json(['results' => null]);
        }

        return response()->json(['results' => array_slice($results, 0, 8)]);
    }

    /** Nombre de la zona local cuyo círculo contiene el punto (la más cercana), o null. */
    private function customPlaceAt(float $lat, float $lng): ?string
    {
        $best = null;
        $bestD = null;
        foreach (CustomPlace::activeCached() as $p) {
            $d = $this->haversine($lat, $lng, $p['lat'], $p['lng']);
            if ($d <= $p['radius_m'] && ($bestD === null || $d < $bestD)) {
                $bestD = $d;
                $best = $p['name'];
            }
        }

        return $best;
    }

    /** Zonas locales que coinciden con el texto (por nombre o apodo). */
    private function customPlaceSearch(string $q): array
    {
        $needle = mb_strtolower($q);
        $out = [];
        foreach (CustomPlace::activeCached() as $p) {
            foreach ($p['names'] as $n) {
                if (mb_strpos(mb_strtolower($n), $needle) !== false) {
                    $out[] = [
                        'label' => $p['name'],
                        'full'  => 'Zona local · MajesGo',
                        'lat'   => $p['lat'],
                        'lng'   => $p['lng'],
                    ];
                    break;
                }
            }
        }

        return $out;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function googleReverse(float $lat, float $lng): ?string
    {
        if (! $this->googleEnabled()) {
            return null;
        }
        try {
            $r = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'latlng'   => "$lat,$lng",
                'language' => 'es',
                'region'   => 'pe',
                'key'      => $this->key(),
            ]);
            $d = $r->json();
            if (($d['status'] ?? '') !== 'OK') {
                if (($d['status'] ?? '') !== 'ZERO_RESULTS') {
                    $this->markGoogleDown();
                }
                return null;
            }
            return $this->shortLabel($d['results'][0] ?? null);
        } catch (\Throwable $e) {
            $this->markGoogleDown();
            return null;
        }
    }

    private function googleSearch(string $q): ?array
    {
        if (! $this->googleEnabled()) {
            return null;
        }
        try {
            $r = Http::timeout(6)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                'address'    => $q,
                'components' => 'country:PE',
                'language'   => 'es',
                'region'     => 'pe',
                'bounds'     => '-16.45,-72.28|-16.28,-72.10', // sesga hacia Majes/El Pedregal
                'key'        => $this->key(),
            ]);
            $d = $r->json();
            if (($d['status'] ?? '') !== 'OK') {
                if (($d['status'] ?? '') !== 'ZERO_RESULTS') {
                    $this->markGoogleDown();
                }
                return null;
            }
            // Tipos demasiado genéricos: apuntan a todo un barrio/distrito, no a un lugar puntual.
            // Si Google solo devuelve eso (típico al buscar un negocio por nombre en zona poco mapeada),
            // lo descartamos y el frontend usa el respaldo Nominatim, que sí tiene esos POIs por nombre.
            $broad = ['locality', 'political', 'neighborhood', 'sublocality', 'administrative_area_level_1',
                      'administrative_area_level_2', 'administrative_area_level_3', 'postal_code', 'plus_code', 'country'];
            $out = [];
            foreach (array_slice($d['results'] ?? [], 0, 8) as $res) {
                $loc = $res['geometry']['location'] ?? null;
                if (! $loc) {
                    continue;
                }
                // Solo la zona de Majes/El Pedregal: 'bounds' de Google es sesgo, no filtro,
                // así que puede devolver calles homónimas de Lima u otra ciudad. Recortamos duro.
                if (abs((float) $loc['lat'] + 16.3627) > 0.5 || abs((float) $loc['lng'] + 72.1908) > 0.5) {
                    continue;
                }
                $types = $res['types'] ?? [];
                $isBroad = $types && ! array_diff($types, $broad); // todos sus tipos son genéricos
                $fa = $res['formatted_address'] ?? '';
                $isPlusCode = (bool) preg_match('/^[A-Z0-9]{4,}\+[A-Z0-9]/', $fa); // p. ej. "JRP5+529"
                if (($res['partial_match'] ?? false) && $isBroad) {
                    continue; // coincidencia parcial que solo acertó el barrio → no sirve
                }
                if ($isBroad || $isPlusCode) {
                    continue;
                }
                $out[] = [
                    'label'     => $this->shortLabel($res),
                    'full'      => $fa,
                    'lat'       => (float) $loc['lat'],
                    'lng'       => (float) $loc['lng'],
                ];
                if (count($out) >= 6) {
                    break;
                }
            }
            return $out; // puede ser [] → el frontend cae a Nominatim
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Etiqueta corta (2 partes) desde un resultado de Google, sin país ni código postal. */
    private function shortLabel(?array $res): ?string
    {
        if (! $res) {
            return null;
        }
        $fa = $res['formatted_address'] ?? null;
        if (! $fa) {
            return null;
        }
        // quitar "Perú" y códigos postales; quedarnos con las 2 partes más específicas
        $clean = [];
        foreach (array_map('trim', explode(',', $fa)) as $p) {
            if ($p === '' || $p === 'Perú') {
                continue;
            }
            $p = trim(preg_replace('/\b\d{4,6}\b/', '', $p));
            if ($p !== '') {
                $clean[] = $p;
            }
        }
        $label = implode(', ', array_slice($clean, 0, 2));
        return $label !== '' ? $label : $fa;
    }
}
