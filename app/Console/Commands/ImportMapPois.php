<?php

namespace App\Console\Commands;

use App\Models\MapPoi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Trae los puntos de referencia desde OpenStreetMap (Overpass) y los guarda.
 *
 *   php artisan pois:import
 *   php artisan pois:import --bbox=-16.415,-72.245,-16.335,-72.170 --dry
 *
 * Es repetible: al reimportar actualiza los que ya existían por su osm_id en vez de
 * duplicarlos, y respeta lo que la central haya editado o desactivado a mano.
 */
class ImportMapPois extends Command
{
    protected $signature = 'pois:import
        {--bbox=-16.415,-72.245,-16.335,-72.170 : sur,oeste,norte,este}
        {--dry : solo muestra lo que importaría}';

    protected $description = 'Importa puntos de referencia del mapa desde OpenStreetMap';

    /** Etiqueta OSM => [categoría nuestra]. Lo que no esté aquí, no se importa. */
    private const MAP = [
        'fuel' => 'grifo',
        'marketplace' => 'mercado', 'supermarket' => 'mercado', 'mall' => 'mercado', 'convenience' => 'mercado',
        'hotel' => 'hotel', 'hostel' => 'hotel', 'guest_house' => 'hotel', 'motel' => 'hotel',
        'bank' => 'banco', 'atm' => 'banco',
        'pharmacy' => 'farmacia',
        'hospital' => 'salud', 'clinic' => 'salud', 'doctors' => 'salud',
        'bus_station' => 'terminal',
        'townhall' => 'municipalidad',
        'police' => 'policia',
        'restaurant' => 'comida', 'fast_food' => 'comida', 'cafe' => 'comida', 'bakery' => 'comida', 'bar' => 'comida',
        'place_of_worship' => 'iglesia',
        'school' => 'colegio', 'college' => 'colegio', 'university' => 'colegio',
        'car_repair' => 'taller', 'hardware' => 'taller',
    ];

    /**
     * Nidos y cunas: en El Pedregal OSM tiene decenas de "Institución educativa inicial…".
     * Nadie le dice a un taxista "llévame al nido Los Angelitos", y llenan el mapa.
     */
    private const SKIP_NAME = '/inicial|cuna\b|jard[ií]n de ni|nido\b|pronoei/iu';

    public function handle(): int
    {
        $bbox = $this->option('bbox');
        $query = <<<OVER
        [out:json][timeout:90];
        (
          node["amenity"]($bbox); way["amenity"]($bbox);
          node["shop"]($bbox);    way["shop"]($bbox);
          node["tourism"]($bbox); way["tourism"]($bbox);
        );
        out center tags;
        OVER;

        $this->info('Consultando OpenStreetMap…');
        $res = Http::timeout(120)
            ->withHeaders(['User-Agent' => 'MajesGo/1.0 (app de taxi, El Pedregal)'])
            ->asForm()->post('https://overpass-api.de/api/interpreter', ['data' => $query]);

        if (! $res->successful()) {
            $this->error('Overpass respondió ' . $res->status() . '. Intenta de nuevo en unos minutos.');
            return self::FAILURE;
        }

        $rows = [];
        foreach ($res->json('elements', []) as $e) {
            $tags = $e['tags'] ?? [];
            $name = trim($tags['name'] ?? '');
            if ($name === '') {
                continue; // sin nombre no sirve como referencia
            }

            $key = $tags['amenity'] ?? $tags['shop'] ?? $tags['tourism'] ?? null;
            $category = self::MAP[$key] ?? null;
            if (! $category) {
                continue;
            }
            if ($category === 'colegio' && preg_match(self::SKIP_NAME, $name)) {
                continue;
            }

            $lat = $e['lat'] ?? ($e['center']['lat'] ?? null);
            $lng = $e['lon'] ?? ($e['center']['lon'] ?? null);
            if ($lat === null || $lng === null) {
                continue;
            }

            $rows[] = [
                'osm_id'   => $e['id'],
                'name'     => mb_substr($name, 0, 60),
                'category' => $category,
                'priority' => MapPoi::DEFAULT_PRIORITY[$category] ?? 3,
                'lat'      => round($lat, 7),
                'lng'      => round($lng, 7),
            ];
        }

        $this->line('Encontrados: ' . count($rows) . ' puntos utilizables.');
        $byCat = collect($rows)->countBy('category')->sortDesc();
        foreach ($byCat as $cat => $n) {
            $this->line(sprintf('  %-14s %d', $cat, $n));
        }

        if ($this->option('dry')) {
            $this->comment('Modo --dry: no se guardó nada.');
            return self::SUCCESS;
        }

        $new = $upd = 0;
        foreach ($rows as $r) {
            $poi = MapPoi::withoutEvents(fn () => MapPoi::firstOrNew(['source' => 'osm', 'osm_id' => $r['osm_id']]));

            // si la central le cambió el nombre o lo desactivó, no se pisa al reimportar
            if ($poi->exists) {
                $poi->fill(['lat' => $r['lat'], 'lng' => $r['lng']]);
                $upd++;
            } else {
                $poi->fill($r + ['source' => 'osm', 'active' => true]);
                $new++;
            }
            MapPoi::withoutEvents(fn () => $poi->save());
        }

        MapPoi::flushCache();

        $this->info("Listo. Nuevos: {$new} · actualizados: {$upd} · total activos: " . MapPoi::where('active', true)->count());

        return self::SUCCESS;
    }
}
