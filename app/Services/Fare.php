<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Cálculo del precio sugerido. Todos los parámetros son configurables desde el panel.
 * El pasajero puede proponer su propio precio (estilo inDrive), con un piso razonable.
 */
class Fare
{
    public static function suggest(int $distanceM, int $durationS): float
    {
        $base   = (float) Setting::get('fare_base', 3.00);
        $perKm  = (float) Setting::get('fare_per_km', 1.20);
        $perMin = (float) Setting::get('fare_per_min', 0.30);
        $min    = (float) Setting::get('fare_min', 4.00);

        $price = $base
               + $perKm * ($distanceM / 1000)
               + $perMin * ($durationS / 60);

        // redondeo a S/ 0.50 hacia arriba, con piso
        $price = max($min, $price);
        $price = ceil($price * 2) / 2;

        return round($price, 2);
    }

    /** Precio mínimo que el pasajero puede ofertar (para que sea atractivo a un conductor). */
    public static function floor(float $suggested): float
    {
        $pct = (float) Setting::get('fare_offer_floor_pct', 0.70); // 70% del sugerido
        $min = (float) Setting::get('fare_min', 4.00);
        return max($min, round($suggested * $pct, 2));
    }
}
