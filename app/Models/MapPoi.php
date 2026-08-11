<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Punto de referencia del mapa. Lo que el pasajero usa para ubicarse:
 * "estoy al lado del grifo", "en la puerta del mercado".
 */
class MapPoi extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'lat'      => 'float',
        'lng'      => 'float',
        'active'   => 'boolean',
        'priority' => 'integer',
    ];

    /** Categorías admitidas y con qué nombre se muestran en el panel. */
    public const CATEGORIES = [
        'grifo'         => 'Grifo',
        'mercado'       => 'Mercado / tienda',
        'hotel'         => 'Hotel / hostal',
        'banco'         => 'Banco / cajero',
        'farmacia'      => 'Farmacia',
        'salud'         => 'Posta / clínica',
        'terminal'      => 'Terminal / paradero',
        'municipalidad' => 'Municipalidad',
        'policia'       => 'Comisaría',
        'comida'        => 'Restaurante / café',
        'iglesia'       => 'Iglesia',
        'colegio'       => 'Colegio / instituto',
        'taller'        => 'Taller / ferretería',
        'otro'          => 'Otro',
    ];

    /** Prioridad por defecto de cada categoría (1 = referencia fuerte, se ve desde lejos). */
    public const DEFAULT_PRIORITY = [
        'grifo' => 1, 'mercado' => 1, 'hotel' => 1, 'banco' => 1, 'farmacia' => 1,
        'salud' => 1, 'terminal' => 1, 'municipalidad' => 1, 'policia' => 1,
        'comida' => 2, 'iglesia' => 2, 'colegio' => 2,
        'taller' => 3, 'otro' => 3,
    ];

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    /**
     * Los POIs activos, en el formato mínimo que consume el mapa.
     * Se cachea porque las dos apps lo piden al abrir y cambia muy de vez en cuando.
     */
    public static function forMap(): array
    {
        return Cache::rememberForever('map.pois', function () {
            return self::query()
                ->where('active', true)
                ->orderBy('priority')
                ->get(['name', 'category', 'priority', 'lat', 'lng'])
                ->map(fn ($p) => [
                    'n' => $p->name,
                    'c' => $p->category,
                    'p' => $p->priority,
                    'y' => round($p->lat, 6),
                    'x' => round($p->lng, 6),
                ])
                ->all();
        });
    }

    public static function flushCache(): void
    {
        Cache::forget('map.pois');
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }
}
