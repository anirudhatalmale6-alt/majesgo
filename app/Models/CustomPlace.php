<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CustomPlace extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'lat'        => 'float',
        'lng'        => 'float',
        'active'     => 'boolean',
        'is_primary' => 'boolean',
    ];

    protected static function booted(): void
    {
        // invalidar la caché cuando el admin agrega/edita/borra una zona
        static::saved(fn () => Cache::forget('custom_places'));
        static::deleted(fn () => Cache::forget('custom_places'));
    }

    /**
     * Zonas activas como ARRAYS planos (cacheado). Se guarda como array —no modelos—
     * para evitar problemas de serialización en algunos drivers de caché.
     * @return array<int,array{name:string,names:array<string>,lat:float,lng:float,radius_m:int}>
     */
    public static function activeCached(): array
    {
        return Cache::remember('custom_places', now()->addMinutes(30), function () {
            return static::where('active', true)->get()->map(fn ($p) => [
                'name'     => $p->name,
                'names'    => $p->names(),
                'lat'      => (float) $p->lat,
                'lng'      => (float) $p->lng,
                'radius_m' => (int) $p->radius_m,
                'primary'  => (bool) $p->is_primary,
            ])->all();
        });
    }

    /** Nombre de la zona local cuyo círculo contiene el punto (la más cercana), o null. */
    public static function zoneAt(float $lat, float $lng): ?string
    {
        $best = null;
        $bestD = null;
        foreach (self::activeCached() as $p) {
            $d = self::haversineM($lat, $lng, $p['lat'], $p['lng']);
            if ($d <= $p['radius_m'] && ($bestD === null || $d < $bestD)) {
                $bestD = $d;
                $best = $p['name'];
            }
        }

        return $best;
    }

    private static function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Lista de nombres a comparar (nombre + apodos). */
    public function names(): array
    {
        $out = [$this->name];
        foreach (explode(',', (string) $this->aliases) as $a) {
            $a = trim($a);
            if ($a !== '') {
                $out[] = $a;
            }
        }
        return $out;
    }
}
