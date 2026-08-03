<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Ride;
use App\Models\Setting;

/**
 * Emparejamiento de viajes: encuentra conductores elegibles cerca del punto de recojo.
 * Un conductor es elegible si está: (1) Disponible, (2) cuenta Activa,
 * (3) con saldo suficiente para la comisión, (4) dentro del radio.
 * (La app del CONDUCTOR — Hito 3 — usará esto para recibir la alerta y aceptar.)
 */
class Dispatch
{
    /**
     * @param  array<int>  $excludeIds  conductores a excluir (p.ej. los que el pasajero ya rechazó)
     * @return array<int,array{driver:Driver, distance_m:float}> ordenado por cercanía
     */
    public static function eligibleDrivers(float $lat, float $lng, ?float $radiusKm = null, array $excludeIds = []): array
    {
        $radiusKm ??= (float) Setting::get('dispatch_radius_km', 3.0);
        $commission = (float) Setting::get('commission_value', 0.50);
        $staleS = (int) Setting::get('driver_stale_s', 60);

        $drivers = Driver::query()
            ->where('status', 'disponible')
            ->where('account_status', 'activo')
            ->where('saldo', '>=', $commission)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            // solo conductores realmente activos (la app reporta ubicación cada pocos segundos);
            // así un conductor que cerró la app sin desconectarse no bloquea el despacho.
            ->where(function ($q) use ($staleS) {
                $q->where('last_active_at', '>=', now()->subSeconds($staleS))
                  ->orWhere('is_demo', true);
            })
            ->get();

        $out = [];
        foreach ($drivers as $d) {
            if (in_array($d->id, $excludeIds, true)) {
                continue;
            }
            $dist = Routing::haversine($lat, $lng, (float) $d->lat, (float) $d->lng);
            if ($dist <= $radiusKm * 1000) {
                $out[] = ['driver' => $d, 'distance_m' => $dist];
            }
        }

        usort($out, fn ($a, $b) => $a['distance_m'] <=> $b['distance_m']);

        return $out;
    }

    /**
     * Si una oferta ('ofrecido') pasó del tiempo límite sin respuesta del pasajero,
     * se libera: vuelve a 'solicitando', se excluye a ese conductor y se le libera.
     * @return bool true si se liberó por vencimiento
     */
    public static function releaseOfferIfExpired(Ride $ride): bool
    {
        if ($ride->status !== 'ofrecido' || ! $ride->offered_at) {
            return false;
        }
        $timeout = (int) Setting::get('offer_timeout_s', 15);
        if ((int) abs($ride->offered_at->diffInSeconds(now())) < $timeout) {
            return false;
        }
        self::releaseOffer($ride);

        return true;
    }

    /** Devuelve el viaje a búsqueda y excluye al conductor ofrecido (rechazo o vencimiento). */
    public static function releaseOffer(Ride $ride): void
    {
        $driverId = $ride->driver_id;
        $excluded = (array) $ride->excluded_driver_ids;
        if ($driverId) {
            $excluded[] = $driverId;
        }

        $ride->forceFill([
            'status'              => 'solicitando',
            'driver_id'           => null,
            'excluded_driver_ids' => array_values(array_unique($excluded)),
            'offered_at'          => null,
            'route_to_pickup'     => null,
            'is_demo'             => false,
        ])->save();

        if ($driverId && ($d = Driver::find($driverId)) && ! $d->activeRide()) {
            $d->update(['status' => 'disponible']);
        }
    }

    /**
     * Conductor de simulación para probar el flujo del pasajero antes de la app del conductor (Hito 3).
     * Lo posiciona a una distancia corta del recojo para que se vea acercándose.
     */
    public static function demoDriver(float $pickupLat, float $pickupLng): Driver
    {
        $driver = Driver::firstOrNew(['code' => 'MG-DEMO']);

        if (! $driver->exists) {
            $driver->fill([
                'full_name'     => 'Carlos (demo)',
                'phone'         => 'DEMO-0000',
                'password'      => bcrypt('demo'),
                'vehicle_make'  => 'Toyota',
                'vehicle_model' => 'Yaris',
                'vehicle_plate' => 'V7A-482',
                'vehicle_color' => 'Blanco',
                'rating'        => 4.90,
                'total_trips'   => 128,
                'saldo'         => 20.00,
                'is_demo'       => true,
            ]);
        }

        // ubicarlo ~600-900 m del recojo, en dirección aleatoria estable por viaje
        $bearing = deg2rad(mt_rand(0, 359));
        $d = mt_rand(600, 900);
        $r = 6371000;
        $lat2 = asin(sin(deg2rad($pickupLat)) * cos($d / $r)
              + cos(deg2rad($pickupLat)) * sin($d / $r) * cos($bearing));
        $lng2 = deg2rad($pickupLng) + atan2(
            sin($bearing) * sin($d / $r) * cos(deg2rad($pickupLat)),
            cos($d / $r) - sin(deg2rad($pickupLat)) * sin($lat2)
        );

        $driver->lat = round(rad2deg($lat2), 7);
        $driver->lng = round(rad2deg($lng2), 7);
        $driver->status = 'disponible';
        $driver->account_status = 'activo';
        $driver->is_demo = true;
        $driver->save();

        return $driver;
    }
}
