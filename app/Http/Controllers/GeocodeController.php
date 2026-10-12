<?php

namespace App\Http\Controllers;

use App\Models\CustomPlace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Proxy de búsqueda de lugares. La clave de Google vive sólo acá, nunca en el navegador,
 * y todo se cachea para no pagar dos veces la misma consulta.
 *
 * DOS APIS DE GOOGLE, CADA UNA PARA LO SUYO — no son intercambiables:
 *   · search()  -> Places API (New) Autocomplete: busca lugares POR NOMBRE.
 *   · reverse() -> Geocoding API: convierte COORDENADAS en un nombre (el pin del mapa).
 *
 * Durante meses search() usó Geocoding y por eso el buscador parecía roto: medido contra
 * las 12 búsquedas más frecuentes de los pasajeros, Geocoding aportaba 0. Es un traductor
 * de direcciones, no un buscador de sitios. Si alguien vuelve a mezclarlas, el síntoma es
 * ese: respuestas tipo "Perú" o lugares de otra ciudad.
 *
 * Si Google falla o no hay clave, se devuelve null y el navegador cae a Nominatim (OSM).
 */
class GeocodeController extends Controller
{
    /** El Pedregal. Centro del círculo al que se limita la búsqueda. */
    private const CENTRO_LAT = -16.3627;
    private const CENTRO_LNG = -72.1908;

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
        // El mínimo de 3 letras evita consultar con cada tecla… pero dejaba fuera el nombre
        // más corto y más usado del campo: la sección, que son DOS caracteres ("D3", "E8").
        // Quien escribía su sección a secas veía la pantalla vacía.
        $esSeccion = (bool) preg_match('/^[A-Ea-e]\s*-?\s*\d{1,2}$/u', $q);
        if (mb_strlen($q) < 3 && ! $esSeccion) {
            return response()->json(['results' => null]);
        }

        // 0) ZONAS LOCALES de MajesGo primero (aparecen arriba en las sugerencias)
        $custom = $this->customPlaceSearch($q);

        // 0.b) PUNTOS DE REFERENCIA (grifos, mercados, farmacias…): la gente escribe
        // "inkafarma" o "el ovalo" antes que una dirección con calle y número.
        $pois = $this->mapPoiSearch($q);

        // 0.c) DIRECCIÓN DE PARCELA ("D3 Parcela 13", "E5 parcela 7").
        // Así se ubica media Majes: la irrigación se divide en secciones y parcelas, y en el
        // campo nadie da una calle. Ningún mapa del mundo tiene las parcelas una por una,
        // pero las SECCIONES sí están cargadas, y llevar al conductor a la sección correcta
        // es infinitamente mejor que una pantalla vacía.
        // Va aparte porque la búsqueda por palabras no puede encontrarlo: exige que estén
        // TODAS, y el lugar se llama "D3" a secas, sin "parcela" ni "13".
        $parcela = $this->seccionDeParcela($q);

        // 1) GOOGLE PLACES (New) — busca lugares POR NOMBRE, que es como escribe la gente.
        //
        // Reemplaza a la Geocoding API, que se usaba acá y aportaba 0 de 12 sobre las
        // búsquedas reales de los pasajeros: Geocoding traduce DIRECCIONES a coordenadas, no
        // busca sitios por nombre. Devolvía "Perú" para "Terminal" y El Ovalo de CUSCO para
        // "Ovalo". Places encuentra "Mercado metropolitano, Majes", que no tenía nadie.
        // Geocoding se sigue usando en reverse() — ahí sí es la herramienta correcta.
        //
        // El sessionToken llega del navegador y ata todo lo que el pasajero teclea con la
        // única consulta que se cobra, la de cuando ELIGE. Sin él, Google cobra cada tecla.
        $sesion = (string) $request->query('s', '');
        $ck = 'geo:pl:' . mb_strtolower($q);
        $places = Cache::remember($ck, now()->addDays(7), fn () => $this->placesAutocomplete($q, $sesion));
        if ($places === null) {
            Cache::forget($ck);
        }

        $results = array_merge($parcela, $custom, $pois, $places ?? []);
        // si no hay ni zonas locales ni Google, devolver null para que el frontend use Nominatim
        if (! $results) {
            return response()->json(['results' => null]);
        }

        return response()->json(['results' => array_slice($results, 0, 8)]);
    }

    /**
     * Puntos de referencia que coinciden con el texto escrito.
     *
     * Se buscan TODAS LAS PALABRAS por separado, no la frase entera.
     *
     * ⚠ Con la frase literal, quien escribía "plaza pionero" no encontraba nada: el lugar se
     * llama "Plaza El Pionero" y ese "El" del medio rompía la coincidencia. La gente teclea
     * las palabras que recuerda, en orden, saltándose los artículos — y se llevaba una
     * pantalla vacía estando el punto cargado. Lo mismo con "mercado metropolitano" o
     * "parque amor".
     */
    private function mapPoiSearch(string $q): array
    {
        $palabras = array_slice(array_filter(preg_split('/\s+/', trim($q))), 0, 5);
        if (! $palabras) {
            return [];
        }

        return \App\Models\MapPoi::query()
            ->where('active', true)
            ->where(function ($w) use ($palabras) {
                foreach ($palabras as $p) {
                    // Cada palabra tiene que estar… pero "avenida" y "Av." son la misma
                    // palabra para quien escribe. El plano catastral guarda "Av. Islay" y
                    // quien teclea "Avenida Islay" no encontraba nada.
                    $variantes = self::variantes($p);
                    $w->where(function ($o) use ($variantes) {
                        foreach ($variantes as $v) {
                            $o->orWhere('name', 'like', '%' . $v . '%');
                        }
                    });
                }
            })
            ->orderBy('priority')
            ->limit(4)
            ->get()
            ->map(fn ($p) => [
                'label' => $p->name,
                'full'  => $p->categoryLabel() . ' · El Pedregal',
                'lat'   => (float) $p->lat,
                'lng'   => (float) $p->lng,
            ])
            ->all();
    }

    /**
     * Las formas en que se escribe el MISMO tipo de vía.
     *
     * El plano catastral de la municipalidad usa las abreviaturas ("Av.", "Jr."); la gente
     * escribe la palabra entera tan seguido como la abreviatura. Sin esto, "Avenida Islay"
     * no encontraba "Av. Islay" — y el punto estaba cargado.
     */
    private const SINONIMOS = [
        ['avenida', 'av'],
        ['jiron', 'jirón', 'jr'],
        ['pasaje', 'pje'],
        ['calle', 'ca'],
        ['prolongacion', 'prolongación', 'prol'],
        ['carretera', 'ctra'],
    ];

    /** @return array<string> la palabra y sus equivalentes, para buscar por cualquiera. */
    private static function variantes(string $palabra): array
    {
        $limpia = mb_strtolower(rtrim($palabra, '.'));
        foreach (self::SINONIMOS as $grupo) {
            if (in_array($limpia, $grupo, true)) {
                return $grupo;
            }
        }

        return [$palabra];
    }

    /**
     * "D3 Parcela 13" -> la sección D3.
     *
     * Reconoce el código de sección al principio del texto (D3, E5, B1…) seguido de algo que
     * suene a parcela o a un número. Devuelve la sección con el subtítulo diciendo CLARAMENTE
     * que es la sección y no la parcela: prometer una precisión que no tenemos haría que el
     * conductor se plante en el sitio equivocado creyendo que llegó.
     */
    private function seccionDeParcela(string $q): array
    {
        // ZONA A: una sola sección, parcelas 1..590, SIN número de sección ("A parcela 250",
        // "A 250", "A-250"). La central confirmó que A1, A2 y A3 NO existen.
        // ⚠ Va ANTES de la regla general: si no, "A 25" se leería como una sección "A25".
        if (preg_match('/^\s*A\s*[-.]?\s*(?:parcela|parc\.?|lote|lt\.?|p\.?)?\s*(\d{1,3})\s*$/iu', $q, $ma)) {
            $codigo = 'A';
            $resto = $ma[1];
        } else {
            if (! preg_match('/^\s*([B-Eb-e])\s*-?\s*(\d{1,2})\b\s*(.*)$/u', $q, $m)) {
                return [];
            }
            $resto = trim($m[3]);
            // "D3" solo ya lo encuentra la búsqueda normal por nombre. Acá interesa el caso en
            // que viene acompañado — "parcela 13", "lote 4", "- 13" — que es el que no encaja.
            if ($resto === '' || ! preg_match('/parcel|lote|mz|manzana|\d/iu', $resto)) {
                return [];
            }
            $codigo = mb_strtoupper($m[1]) . (int) $m[2];
        }

        // 1) LA PARCELA EXACTA, si esa sección ya está cargada desde el plano municipal.
        // El número es el primero que aparezca después de la sección: "parcela 15",
        // "lote 15", "15", "- 15".
        $numero = preg_match('/(\d{1,4})/u', $resto, $n) ? (int) $n[1] : null;
        if ($numero !== null) {
            $parcela = \Illuminate\Support\Facades\DB::table('parcels')
                ->where('section', $codigo)
                ->where('number', $numero)
                ->first();
            if ($parcela) {
                return [[
                    'label' => 'Parcela ' . $numero . ' · ' . $codigo,
                    'full'  => 'Sección ' . $codigo . ' · según plano municipal',
                    'lat'   => (float) $parcela->lat,
                    'lng'   => (float) $parcela->lng,
                ]];
            }
        }

        // 2) Si no, la SECCIÓN — diciendo claramente que no es la parcela. Si la sección
        // está cargada pero ESE número no existe, se dice: así el pasajero revisa lo que
        // escribió en vez de creer que lo dejamos en su puerta.
        $p = \App\Models\MapPoi::query()
            ->where('active', true)
            ->where('category', 'sector')
            ->whereRaw('UPPER(name) = ?', [$codigo])
            ->first();
        if (! $p) {
            return [];
        }
        // ⚠ "No la tenemos" NO es "no existe". En el plano faltan rótulos sueltos (C2 trae
        // 113 de sus 114): decirle "revisa el número" a quien vive en esa parcela sería
        // acusarlo de equivocarse. Sólo un número MAYOR que la última parcela de la sección
        // está realmente fuera de rango.
        // El total que dio la central manda; si no lo tenemos, el último rótulo del plano.
        $ultima = \Illuminate\Support\Facades\DB::table('parcel_sections')->where('section', $codigo)->value('total')
            ?? \Illuminate\Support\Facades\DB::table('parcels')->where('section', $codigo)->max('number');
        if ($numero !== null && $ultima !== null && $numero > $ultima) {
            $sub = $codigo . ' llega hasta la parcela ' . $ultima . ' · revisa el número';
        } elseif ($numero !== null && \Illuminate\Support\Facades\DB::table('parcels')->where('section', $codigo)->exists()) {
            // sólo tiene sentido "no figura en el plano" si el plano de esa sección está cargado
            $sub = 'La parcela ' . $numero . ' no figura en el plano · te dejamos en ' . $codigo . ', escríbela en la referencia';
        } else {
            $sub = 'Te dejamos en ' . $codigo . ' · escribe la parcela en la referencia';
        }

        return [[
            'label' => 'Sección ' . $codigo,
            'full'  => $sub,
            'lat'   => (float) $p->lat,
            'lng'   => (float) $p->lng,
        ]];
    }

    /**
     * Sugerencias de Google Places (New) mientras el pasajero escribe.
     *
     * NO devuelven coordenadas: Google las cobra aparte y sólo tiene sentido pedirlas del
     * que el pasajero ELIJA (ver place()). Por eso cada fila viaja con su place_id y sin
     * lat/lng, y el navegador sabe que a esas hay que resolverlas al tocarlas.
     *
     * ⚠⚠ 'locationRestriction' y NO 'locationBias'. Con bias —que es "preferí lo cercano"—
     * la prueba dio 6 aciertos de 12: salía la Plaza de Armas de AREQUIPA, el Terminal de
     * Arequipa y un colegio de TACNA. Con restriction —"sólo dentro de este círculo"— dio
     * 10 de 12. "Preferir" no alcanza cuando el lugar famoso está a 400 km.
     */
    private function placesAutocomplete(string $q, string $sesion): ?array
    {
        if (! $this->googleEnabled()) {
            return null;
        }
        try {
            $cuerpo = [
                'input'               => $q,
                'locationRestriction' => ['circle' => [
                    'center' => ['latitude' => self::CENTRO_LAT, 'longitude' => self::CENTRO_LNG],
                    'radius' => 30000.0,
                ]],
                'languageCode'        => 'es',
                'includedRegionCodes' => ['pe'],
            ];
            if ($sesion !== '') {
                $cuerpo['sessionToken'] = $sesion;
            }

            $r = Http::timeout(6)
                ->withHeaders(['X-Goog-Api-Key' => $this->key()])
                ->post('https://places.googleapis.com/v1/places:autocomplete', $cuerpo);

            if ($r->failed()) {
                $this->markGoogleDown();
                return null;
            }

            $out = [];
            foreach ($r->json()['suggestions'] ?? [] as $s) {
                $p = $s['placePrediction'] ?? null;
                if (! $p || empty($p['placeId'])) {
                    continue;   // las predicciones de consulta ('queryPrediction') no son lugares
                }
                $principal = $p['structuredFormat']['mainText']['text'] ?? ($p['text']['text'] ?? null);
                if (! $principal) {
                    continue;
                }
                $out[] = [
                    'label'    => $principal,
                    'full'     => $p['structuredFormat']['secondaryText']['text'] ?? '',
                    'lat'      => null,          // se piden al elegir, no antes
                    'lng'      => null,
                    'place_id' => $p['placeId'],
                ];
                if (count($out) >= 6) {
                    break;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            $this->markGoogleDown();
            return null;
        }
    }

    /**
     * Coordenadas del lugar que el pasajero eligió. Es la ÚNICA llamada que se cobra.
     *
     * El sessionToken tiene que ser EL MISMO que se usó mientras escribía: así Google cobra
     * una sesión en vez de una consulta por tecla.
     */
    public function place(Request $request)
    {
        $id = (string) $request->query('id', '');
        // Los ids de Google son alfanuméricos con - y _. Filtrar acá evita que un id raro
        // se concatene a la URL y termine pegándole a otra ruta de la API de Google.
        if ($id === '' || ! preg_match('/^[A-Za-z0-9_\-]{10,256}$/', $id)) {
            return response()->json(['message' => 'Lugar no válido.'], 422);
        }
        if (! $this->key()) {
            return response()->json(['message' => 'Búsqueda no disponible.'], 503);
        }

        $sesion = (string) $request->query('s', '');

        // Un mismo destino lo eligen muchas personas (el mercado, el terminal): cachearlo
        // 30 días evita pagar la misma consulta una y otra vez. Las coordenadas de un lugar
        // no se mueven.
        $datos = Cache::remember('geo:place:' . $id, now()->addDays(30), function () use ($id, $sesion) {
            try {
                $r = Http::timeout(6)->withHeaders([
                    'X-Goog-Api-Key'   => $this->key(),
                    'X-Goog-FieldMask' => 'id,displayName,formattedAddress,location',
                ])->get('https://places.googleapis.com/v1/places/' . $id, $sesion !== '' ? ['sessionToken' => $sesion] : []);

                if ($r->failed()) {
                    return null;
                }
                $d = $r->json();
                $loc = $d['location'] ?? null;
                if (! $loc || ! isset($loc['latitude'], $loc['longitude'])) {
                    return null;
                }

                return [
                    'label' => $d['displayName']['text'] ?? ($d['formattedAddress'] ?? 'Destino'),
                    'full'  => $d['formattedAddress'] ?? '',
                    'lat'   => (float) $loc['latitude'],
                    'lng'   => (float) $loc['longitude'],
                ];
            } catch (\Throwable $e) {
                return null;
            }
        });

        if (! $datos) {
            Cache::forget('geo:place:' . $id);   // un fallo no se cachea 30 días

            return response()->json(['message' => 'No pudimos ubicar ese lugar.'], 502);
        }

        return response()->json($datos);
    }

    /** Zonas locales activas (para dibujar sus nombres en el mapa del pasajero). */
    public function zones()
    {
        $out = array_map(fn ($p) => [
            'name'     => $p['name'],
            'lat'      => $p['lat'],
            'lng'      => $p['lng'],
            'radius_m' => $p['radius_m'],
            'primary'  => $p['primary'],
        ], CustomPlace::activeCached());

        return response()->json(['zones' => array_values($out)]);
    }

    /**
     * Puntos de referencia del mapa (grifos, mercados, hoteles…).
     * Los consumen las dos apps; son datos públicos, se cachean en el navegador un día.
     */
    public function mapPois()
    {
        return response()
            ->json(['pois' => \App\Models\MapPoi::forMap()])
            ->header('Cache-Control', 'public, max-age=86400');
    }

    /** Nombre de la zona local cuyo círculo contiene el punto (la más cercana), o null. */
    private function customPlaceAt(float $lat, float $lng): ?string
    {
        return CustomPlace::zoneAt($lat, $lng);
    }

    /**
     * Zonas locales que coinciden con el texto (por nombre o apodo).
     * Por palabras, igual que mapPoiSearch — ver el motivo allí.
     */
    private function customPlaceSearch(string $q): array
    {
        $palabras = array_slice(array_filter(preg_split('/\s+/', mb_strtolower(trim($q)))), 0, 5);
        if (! $palabras) {
            return [];
        }
        $out = [];
        foreach (CustomPlace::activeCached() as $p) {
            foreach ($p['names'] as $n) {
                $bajo = mb_strtolower($n);
                $todas = true;
                foreach ($palabras as $w) {
                    if (mb_strpos($bajo, $w) === false) {
                        $todas = false;
                        break;
                    }
                }
                if ($todas) {
                    $out[] = [
                        'label' => $p['name'],
                        'full'  => 'Zona local · Majes Drive',
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
