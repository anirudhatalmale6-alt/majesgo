<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Cálculo de la tarifa y de la comisión. Todos los parámetros son configurables
 * desde el panel de administración.
 *
 * TARIFA POR TIEMPO (regla vigente desde 2026-08-11):
 *   - S/ 1.00 por cada minuto de viaje.
 *   - Tarifa mínima S/ 10.00: cualquier carrera de 10 minutos o menos cuesta S/ 10.00.
 *   - Del minuto 11 en adelante se suma S/ 1.00 por minuto (11 min = S/ 11, 15 min = S/ 15).
 *
 * COMISIÓN: porcentaje de la tarifa (5% por defecto); el 95% restante queda para el conductor.
 *
 * El pasajero puede proponer su propio precio (estilo inDrive), nunca por debajo de floor().
 *
 * ⚠ PRECIO CERRADO — REGLA DE NEGOCIO INNEGOCIABLE
 * suggest() sirve ÚNICAMENTE para proponer un precio ANTES de solicitar el viaje.
 * Una vez que el conductor acepta, el precio pactado (rides.offered_price) queda congelado:
 * el pasajero paga exactamente ese monto aunque el viaje demore el triple por tráfico.
 * NUNCA llames a suggest() al finalizar un viaje ni recalcules la tarifa con el tiempo real
 * transcurrido — final_price siempre se copia de offered_price (ver Driver\RideController::complete).
 */
class Fare
{
    /** Minutos de viaje redondeados al entero más cercano (mínimo 1). */
    public static function minutes(int $durationS): int
    {
        return max(1, (int) round($durationS / 60));
    }

    /**
     * Precio sugerido para el viaje.
     * $distanceM se conserva en la firma porque la ruta ya lo calcula y puede
     * volver a usarse si algún día se vuelve a una tarifa mixta tiempo+distancia.
     */
    public static function suggest(int $distanceM, int $durationS): float
    {
        $perMin = (float) Setting::get('fare_per_min', 1.00);
        $min    = (float) Setting::get('fare_min', 10.00);

        $price = self::minutes($durationS) * $perMin;

        return round(max($min, $price), 2);
    }

    /** Precio mínimo que el pasajero puede ofertar (nunca por debajo de la tarifa mínima). */
    public static function floor(float $suggested): float
    {
        $pct = (float) Setting::get('fare_offer_floor_pct', 0.70); // 70% del sugerido
        $min = (float) Setting::get('fare_min', 10.00);

        return max($min, round($suggested * $pct, 2));
    }

    /** Comisión de la app para una tarifa dada (porcentaje configurable). */
    public static function commission(float $price): float
    {
        $pct = (float) Setting::get('commission_pct', 5.0);
        $min = (float) Setting::get('commission_min', 0.0);

        return max($min, round($price * $pct / 100, 2));
    }

    /** Lo que recibe el conductor tras descontar la comisión. */
    public static function driverEarnings(float $price): float
    {
        return round($price - self::commission($price), 2);
    }

    /**
     * Saldo mínimo para poder recibir viajes: la comisión de la carrera más barata posible.
     * Con tarifa mínima S/ 10 y comisión 5% ⇒ S/ 0.50.
     */
    public static function minSaldo(): float
    {
        return self::commission((float) Setting::get('fare_min', 10.00));
    }

    /** Porcentaje de comisión vigente (para mostrarlo en las apps). */
    public static function commissionPct(): float
    {
        return (float) Setting::get('commission_pct', 5.0);
    }
}
