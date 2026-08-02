<?php

namespace App\Services;

use App\Models\Ride;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Simulación determinista del conductor para PROBAR el flujo del pasajero
 * antes de que exista la app del conductor (Hito 3).
 *
 * No necesita un worker en segundo plano: la posición y el estado se calculan
 * a partir del tiempo transcurrido desde que el conductor "aceptó". Cada vez que
 * el pasajero consulta el estado, el viaje avanza según ese reloj.
 */
class DemoSim
{
    private const WAIT_AT_PICKUP = 5; // segundos que "espera" al recoger

    /**
     * Avanza el viaje demo según el tiempo transcurrido y devuelve la posición actual del conductor.
     * @return array{lat:float, lng:float}
     */
    public static function advance(Ride $ride): array
    {
        $toPickup = $ride->route_to_pickup ?: [];
        $trip     = $ride->route_trip ?: [];

        $pickupSecs = self::phaseSeconds($toPickup);
        $tripSecs   = self::phaseSeconds($trip);

        $start = $ride->accepted_at ?? $ride->requested_at ?? Carbon::now();
        $t = max(0, Carbon::now()->getTimestamp() - $start->getTimestamp());

        // Fase 1: el conductor va al punto de recojo
        if ($t < $pickupSecs) {
            self::moveTo($ride, 'en_camino');
            return self::at($toPickup, $pickupSecs > 0 ? $t / $pickupSecs : 1);
        }

        // Llegó al recojo (breve espera)
        if ($t < $pickupSecs + self::WAIT_AT_PICKUP) {
            self::moveTo($ride, 'llego', ['arrived_at' => now()]);
            return self::at($toPickup, 1);
        }

        // Fase 2: viaje hacia el destino
        $t2 = $t - $pickupSecs - self::WAIT_AT_PICKUP;
        if ($t2 < $tripSecs) {
            self::moveTo($ride, 'a_bordo', ['started_at' => $ride->started_at ?? now()]);
            return self::at($trip, $tripSecs > 0 ? $t2 / $tripSecs : 1);
        }

        // Completado
        self::complete($ride);
        return self::at($trip, 1);
    }

    /** Segundos que dura una fase, acelerada respecto al tiempo real para que sea ágil de probar. */
    private static function phaseSeconds(array $coords): int
    {
        $speed = (float) Setting::get('demo_speed_kmh', 22);
        $scale = (float) Setting::get('demo_time_scale', 4); // 4x más rápido que la vida real
        $meters = self::length($coords);
        $realSecs = $speed > 0 ? ($meters / 1000) / $speed * 3600 : 30;
        return (int) min(60, max(8, round($realSecs / max(1, $scale))));
    }

    private static function moveTo(Ride $ride, string $status, array $extra = []): void
    {
        $changes = [];
        if ($ride->status !== $status) {
            $changes['status'] = $status;
        }
        foreach ($extra as $k => $v) {
            if (empty($ride->$k)) {
                $changes[$k] = $v;
            }
        }
        if ($changes) {
            $ride->forceFill($changes)->save();
        }
    }

    private static function complete(Ride $ride): void
    {
        if ($ride->status === 'completado') {
            return;
        }
        $commission = (float) Setting::get('commission_value', 0.50);

        $ride->forceFill([
            'status'       => 'completado',
            'started_at'   => $ride->started_at ?? now(),
            'completed_at' => now(),
            'final_price'  => $ride->offered_price,
            'commission'   => $commission,
        ])->save();

        if ($driver = $ride->driver) {
            // descuenta la comisión (muestra el mecanismo real de saldo)
            $driver->applyMovement('comision', -$commission, "Comisión viaje {$ride->code}", 'ride', $ride->id);
            $driver->increment('total_trips');
            $driver->update(['status' => 'disponible']);
        }
        if ($p = $ride->passenger) {
            $p->increment('total_trips');
        }
    }

    /* ---- geometría ---- */

    private static function length(array $coords): float
    {
        $sum = 0;
        for ($i = 1; $i < count($coords); $i++) {
            $sum += Routing::haversine($coords[$i - 1][0], $coords[$i - 1][1], $coords[$i][0], $coords[$i][1]);
        }
        return $sum;
    }

    /** Punto a una fracción [0..1] a lo largo de la polilínea. */
    private static function at(array $coords, float $frac): array
    {
        if (count($coords) === 0) {
            return ['lat' => 0, 'lng' => 0];
        }
        if (count($coords) === 1 || $frac <= 0) {
            return ['lat' => (float) $coords[0][0], 'lng' => (float) $coords[0][1]];
        }
        if ($frac >= 1) {
            $last = $coords[count($coords) - 1];
            return ['lat' => (float) $last[0], 'lng' => (float) $last[1]];
        }

        $total = self::length($coords);
        $target = $total * $frac;
        $acc = 0;
        for ($i = 1; $i < count($coords); $i++) {
            $seg = Routing::haversine($coords[$i - 1][0], $coords[$i - 1][1], $coords[$i][0], $coords[$i][1]);
            if ($acc + $seg >= $target && $seg > 0) {
                $f = ($target - $acc) / $seg;
                return [
                    'lat' => round($coords[$i - 1][0] + ($coords[$i][0] - $coords[$i - 1][0]) * $f, 6),
                    'lng' => round($coords[$i - 1][1] + ($coords[$i][1] - $coords[$i - 1][1]) * $f, 6),
                ];
            }
            $acc += $seg;
        }
        $last = $coords[count($coords) - 1];
        return ['lat' => (float) $last[0], 'lng' => (float) $last[1]];
    }
}
