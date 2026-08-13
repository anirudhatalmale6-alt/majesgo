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
 * COSTO DE APROXIMACIÓN (regla vigente desde 2026-08-13):
 *   - Lo anterior cubre solo el tramo A→B. El acercamiento del conductor al recojo se cobra aparte.
 *   - Radio gratuito configurable (3 km por defecto); pasado ese radio, S/ 1.00 por km extra.
 *   - Se calcula con la distancia REAL del conductor que toma el viaje, no de uno hipotético.
 *
 * CONTRAOFERTA DEL CONDUCTOR (regla vigente desde 2026-08-13):
 *   - Al aceptar, el conductor puede añadir uno de los importes cerrados que fija la central
 *     (+3 / +5 por defecto). No escribe montos libres: elige entre botones.
 *   - El pasajero ve el desglose y confirma o busca otro conductor. No hay regateo de ida y vuelta.
 *
 * COMISIÓN: porcentaje de la tarifa (5% por defecto); el 95% restante queda para el conductor.
 * Se aplica sobre el total cobrado (viaje + aproximación + ajuste), que es lo que el pasajero paga.
 *
 * El pasajero puede proponer su propio precio (estilo inDrive), nunca por debajo de floor().
 *
 * ⚠ PRECIO CERRADO — REGLA DE NEGOCIO INNEGOCIABLE
 * suggest() sirve ÚNICAMENTE para proponer un precio ANTES de solicitar el viaje.
 * Una vez que el pasajero confirma al conductor, el total pactado
 * (rides.offered_price + rides.approach_fee + rides.counter_offer) queda congelado: paga ese monto
 * aunque el viaje demore el triple por tráfico. NUNCA llames a suggest() al finalizar un viaje
 * ni recalcules la tarifa con el tiempo real transcurrido — final_price siempre se copia de
 * total() sobre lo ya guardado (ver Driver\RideController::complete).
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

    /* ---------- Costo de aproximación (lo que el conductor recorre hasta el recojo) ---------- */

    public static function approachEnabled(): bool
    {
        return (string) Setting::get('approach_enabled', '1') === '1';
    }

    /**
     * Distancia de aproximación con la que se COBRA, a partir de las coordenadas.
     *
     * ⚠ Siempre por aquí, nunca con la distancia que devuelve OSRM.
     * La tarjeta del conductor recalcula esto cada pocos segundos para cada viaje en pantalla:
     * pedirle una ruta real a OSRM por cada par (conductor, viaje) no es viable. Si la tarjeta
     * estimara en línea recta y el cobro usara la ruta real, el conductor vería S/ 5.00 y se le
     * pagaría otra cosa al aceptar. Un solo criterio para los dos lados: línea recta corregida
     * por el factor de calles.
     */
    public static function approachDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return Routing::streetEstimate($lat1, $lng1, $lat2, $lng2);
    }

    /**
     * Cobro por el acercamiento del conductor al punto de recojo.
     *
     * Los primeros km son gratis (radio base); a partir de ahí se cobra por km, con un tope.
     * Se redondea a S/ 0.50 para que el monto sea "de bolsillo" y fácil de leer en la tarjeta.
     *
     * @param  float  $distanceM  distancia de approachDistance(), en metros
     */
    public static function approach(float $distanceM): float
    {
        if (! self::approachEnabled() || $distanceM <= 0) {
            return 0.0;
        }

        $freeKm = (float) Setting::get('approach_free_km', 3.0);
        $perKm  = (float) Setting::get('approach_per_km', 1.00);
        $max    = (float) Setting::get('approach_max', 15.00);

        $billableKm = max(0.0, $distanceM / 1000 - $freeKm);
        if ($billableKm <= 0 || $perKm <= 0) {
            return 0.0;
        }

        $fee = round($billableKm * $perKm * 2) / 2; // al múltiplo de 0.50 más cercano

        return round(min($fee, $max), 2);
    }

    /** Total que paga el pasajero: tramo A→B + acercamiento + ajuste del conductor. */
    public static function total(float $tripPrice, float $approachFee, float $counterOffer = 0.0): float
    {
        return round($tripPrice + $approachFee + $counterOffer, 2);
    }

    /** Parámetros vigentes del acercamiento (para mostrarlos en las apps). */
    public static function approachRules(): array
    {
        return [
            'enabled' => self::approachEnabled(),
            'free_km' => (float) Setting::get('approach_free_km', 3.0),
            'per_km'  => (float) Setting::get('approach_per_km', 1.00),
            'max'     => (float) Setting::get('approach_max', 15.00),
        ];
    }

    /* ---------- Contraoferta del conductor (importes cerrados, no negociación) ---------- */

    public static function counterEnabled(): bool
    {
        return (string) Setting::get('counter_offer_enabled', '1') === '1'
            && self::counterOptions() !== [];
    }

    /**
     * Importes que el conductor puede añadir, definidos por la central ("3,5" por defecto).
     *
     * Es una lista CERRADA a propósito: el conductor elige entre dos o tres botones, no escribe
     * un monto. Así el pasajero nunca ve un precio inesperado y no hace falta un ida y vuelta
     * de regateo (que sería otra pantalla y otro tiempo de espera).
     *
     * @return array<int,float> ordenados de menor a mayor, máximo 4
     */
    public static function counterOptions(): array
    {
        $raw = (string) Setting::get('counter_offer_options', '3,5');

        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $v = round((float) str_replace(',', '.', trim($piece)), 2);
            if ($v > 0 && ! in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        sort($out);

        return array_slice($out, 0, 4);
    }

    /**
     * Valida el ajuste que mandó la app del conductor.
     *
     * ⚠ Nunca se confía en el monto que llega del celular: si no es EXACTAMENTE uno de los
     * importes configurados (o la contraoferta está apagada), se cobra 0. Un conductor no puede
     * inventarse un "+50" tocando la API por su cuenta.
     */
    public static function counterOffer(float $amount): float
    {
        if ($amount <= 0 || ! self::counterEnabled()) {
            return 0.0;
        }

        return in_array(round($amount, 2), self::counterOptions(), true) ? round($amount, 2) : 0.0;
    }

    /** Parámetros vigentes de la contraoferta (para pintar los botones en la app). */
    public static function counterRules(): array
    {
        return [
            'enabled' => self::counterEnabled(),
            'options' => self::counterEnabled() ? self::counterOptions() : [],
        ];
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
