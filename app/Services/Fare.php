<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Ride;
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
 * CONTRAOFERTA LIBRE (pedido de la central, 24 nov):
 *   - El conductor escribe su precio (o usa los mismos botones como atajo).
 *   - Nunca por debajo de lo que ya paga el pasajero, nunca por encima del tope por carrera
 *     (S/ 25 por defecto). Si la carrera ya llega al tope, no se puede subir: se respeta el precio.
 *   - Se activa por etapas: apagada → sólo los conductores de prueba → todos (counter_free_mode).
 *   - Con contraoferta, el pasajero tiene más segundos para decidir (counter_timeout_s).
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

    public static function counterEnabled(?Driver $driver = null): bool
    {
        if ((string) Setting::get('counter_offer_enabled', '1') !== '1') {
            return false;
        }

        return self::counterOptions() !== [] || ($driver && self::counterFree($driver));
    }

    /**
     * Importes que el conductor puede añadir, definidos por la central ("3,5" por defecto).
     *
     * En el modo cerrado es la lista COMPLETA de lo permitido: el conductor elige entre dos o
     * tres botones, no escribe un monto. En el modo libre son sólo atajos (+3 / +5) junto al
     * campo donde escribe su precio.
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

    /** 'off' | 'prueba' | 'todos' */
    public static function counterFreeMode(): string
    {
        $m = (string) Setting::get('counter_free_mode', 'off');

        return in_array($m, ['off', 'prueba', 'todos'], true) ? $m : 'off';
    }

    /** Códigos de los conductores que prueban la contraoferta libre ("MG-0012, MG-0031"). */
    public static function counterFreeDrivers(): array
    {
        $raw = (string) Setting::get('counter_free_drivers', '');

        return array_values(array_filter(array_map(
            fn ($c) => strtoupper(trim($c)),
            preg_split('/[\s,;]+/', $raw) ?: []
        )));
    }

    /** ¿Este conductor puede escribir su propio precio? */
    public static function counterFree(Driver $driver): bool
    {
        if ((string) Setting::get('counter_offer_enabled', '1') !== '1') {
            return false;
        }

        return match (self::counterFreeMode()) {
            'todos'  => true,
            'prueba' => in_array(strtoupper((string) $driver->code), self::counterFreeDrivers(), true),
            default  => false,
        };
    }

    /** Precio máximo por carrera con contraoferta libre (lo que paga el pasajero en total). */
    public static function counterMaxTotal(): float
    {
        return max(0.0, (float) Setting::get('counter_max_total', 25.00));
    }

    /**
     * Cuánto puede subir el conductor sobre $base (lo que ya paga el pasajero) sin pasar del tope.
     * 0 si la carrera ya llega al tope: ahí se respeta el precio calculado.
     */
    public static function counterRoom(float $base): float
    {
        return max(0.0, round(self::counterMaxTotal() - $base, 2));
    }

    /**
     * Valida el ajuste que mandó la app del conductor.
     *
     * ⚠ Nunca se confía en el monto que llega del celular.
     * - Modo cerrado: si no es EXACTAMENTE uno de los importes configurados (o la contraoferta
     *   está apagada), se cobra 0. Un conductor no puede inventarse un "+50" tocando la API.
     * - Modo libre: se redondea a S/ 0.10 y tiene que caber bajo el tope.
     *   Si no cabe devuelve null y el controlador contesta 422: en el modo libre el conductor
     *   ESCRIBIÓ un precio, y cobrarle otro distinto sin avisar sería peor que rechazarlo.
     *
     * @param  float  $base  lo que paga el pasajero sin contraoferta (viaje + aproximación)
     */
    public static function counterOffer(float $amount, ?Driver $driver = null, float $base = 0.0): ?float
    {
        if ($amount <= 0 || ! self::counterEnabled($driver)) {
            return 0.0;
        }

        if ($driver && self::counterFree($driver)) {
            // a 10 céntimos, igual que la app. No a S/ 0.50: con una carrera de S/ 15.30 el
            // conductor que escribe S/ 18 tiene que pedir 2.70, si no vería 17.80 o 18.30.
            $amount = round($amount, 1);
            if ($amount <= 0) {
                return 0.0;
            }

            return $amount <= self::counterRoom($base) + 0.001 ? $amount : null;
        }

        return in_array(round($amount, 2), self::counterOptions(), true) ? round($amount, 2) : 0.0;
    }

    /** Parámetros vigentes de la contraoferta (para pintar los botones en la app). */
    public static function counterRules(?Driver $driver = null): array
    {
        $free = $driver && self::counterFree($driver);

        return [
            'enabled'   => self::counterEnabled($driver),
            'options'   => self::counterEnabled($driver) ? self::counterOptions() : [],
            'free'      => $free,
            'max_total' => $free ? self::counterMaxTotal() : null,
            'timeout_s' => self::counterTimeout(),
        ];
    }

    /** Segundos que tiene el pasajero para confirmar a un conductor SIN contraoferta. */
    public static function offerTimeoutBase(): int
    {
        return max(5, (int) Setting::get('offer_timeout_s', 15));
    }

    /** Segundos que tiene el pasajero para decidir cuando el conductor pidió más. */
    public static function counterTimeout(): int
    {
        return max(5, (int) Setting::get('counter_timeout_s', 25));
    }

    /** Tiempo límite de ESTA oferta: con contraoferta el pasajero tiene que leer un precio nuevo. */
    public static function offerTimeout(Ride $ride): int
    {
        return (float) $ride->counter_offer > 0 ? self::counterTimeout() : self::offerTimeoutBase();
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
