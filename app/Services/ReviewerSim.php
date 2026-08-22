<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Passenger;
use App\Models\Ride;

/**
 * Simulación para el revisor de Google Play en la APP DE CONDUCTOR.
 *
 * Problema que resuelve: el revisor tiene que poder completar un viaje de punta a punta
 * (aceptar → llegué → iniciar → finalizar) o rechaza la app con "no pudimos revisar la
 * funcionalidad". Pero un conductor conectado es, para el sistema, un conductor más:
 * si lo dejáramos entrar al despacho real podría quedarse con el viaje de un pasajero
 * de verdad. Ver Dispatch::eligibleDrivers(), que excluye is_reviewer.
 *
 * Entonces al revisor se le fabrica su propia solicitud: un viaje is_demo con un pasajero
 * de simulación, ubicado cerca de donde diga estar el revisor. Nadie más lo ve —
 * Driver\RideController::pending() sólo ofrece viajes reales (is_demo = false) al resto.
 *
 * Los viajes que salen de acá quedan marcados is_demo = true, así que Ride::real() los
 * deja fuera de las estadísticas del negocio y no ensucian los números de Joel.
 */
class ReviewerSim
{
    public const DRIVER_CODE   = 'MG-REVIEW';
    public const PASSENGER_PHONE = 'REVIEW-0000';

    /** Distancia a la que se le coloca el pasajero simulado, en metros. */
    private const PICKUP_MIN_M = 500;
    private const PICKUP_MAX_M = 900;

    /** Largo del viaje simulado, en metros. */
    private const TRIP_MIN_M = 1800;
    private const TRIP_MAX_M = 3200;

    /**
     * El pasajero de simulación que figura como solicitante.
     * withTrashed() no aplica: Passenger no usa borrado lógico, pero el teléfono es único,
     * así que firstOrCreate evita chocar contra el índice si ya existe.
     */
    public static function passenger(): Passenger
    {
        return Passenger::firstOrCreate(
            ['phone' => self::PASSENGER_PHONE],
            [
                'name'           => 'Pasajero de prueba',
                'password'       => bcrypt(str()->random(32)), // nadie inicia sesión con esta cuenta
                'rating'         => 5.00,
                'account_status' => 'activo',
            ]
        );
    }

    /**
     * Asegura que el revisor tenga una solicitud esperando cerca suyo.
     * Devuelve null si el conductor no reportó ubicación todavía.
     */
    public static function ensureRequest(Driver $driver): ?Ride
    {
        if ($driver->lat === null || $driver->lng === null) {
            return null;
        }

        $passenger = self::passenger();

        // ¿ya hay una vigente? La ventana es la misma que la del despacho real, así que
        // si venció se deja morir y se fabrica otra: al revisor nunca le queda la pantalla
        // vacía esperando algo que ya expiró.
        $existing = Ride::where('passenger_id', $passenger->id)
            ->where('status', 'solicitando')
            ->where('requested_at', '>=', now()->subSeconds(Dispatch::searchTimeoutS()))
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        // Si el pasajero simulado quedó con un viaje colgado de una revisión anterior
        // (el revisor cerró la app a mitad del viaje), se cierra antes de abrir otro:
        // Passenger::activeRide() sólo admite uno a la vez.
        Ride::where('passenger_id', $passenger->id)
            ->whereIn('status', Ride::ACTIVE_STATES)
            ->update(['status' => 'cancelado', 'cancelled_at' => now()]);

        $origin = self::offset((float) $driver->lat, (float) $driver->lng, mt_rand(self::PICKUP_MIN_M, self::PICKUP_MAX_M));
        $dest   = self::offset($origin[0], $origin[1], mt_rand(self::TRIP_MIN_M, self::TRIP_MAX_M));

        // Ruta real por OSRM cuando se pueda: la tarjeta del conductor dibuja route_trip y
        // una línea recta se nota. Si el router no responde, el viaje igual se crea.
        try {
            $route = Routing::route($origin[0], $origin[1], $dest[0], $dest[1]);
        } catch (\Throwable $e) {
            $route = ['distance_m' => 2400, 'duration_s' => 420, 'geometry' => null];
        }

        $suggested = Fare::suggest((int) $route['distance_m'], (int) $route['duration_s']);

        return Ride::create([
            'code'            => Ride::makeCode(),
            'passenger_id'    => $passenger->id,
            'origin_lat'      => $origin[0],
            'origin_lng'      => $origin[1],
            'origin_address'  => 'Punto de recojo de prueba',
            'reference'       => 'Viaje de demostración para revisión de Google Play',
            'dest_lat'        => $dest[0],
            'dest_lng'        => $dest[1],
            'dest_address'    => 'Destino de prueba',
            'distance_m'      => $route['distance_m'],
            'duration_s'      => $route['duration_s'],
            'suggested_price' => $suggested,
            'offered_price'   => $suggested,
            'payment_method'  => 'efectivo',
            'route_trip'      => $route['geometry'],
            'status'          => 'solicitando',
            'requested_at'    => now(),
            'is_demo'         => true,
        ]);
    }

    /** Punto a $meters del origen, en una dirección al azar. */
    private static function offset(float $lat, float $lng, int $meters): array
    {
        $bearing = deg2rad(mt_rand(0, 359));
        $r = 6371000;

        $lat2 = asin(sin(deg2rad($lat)) * cos($meters / $r)
              + cos(deg2rad($lat)) * sin($meters / $r) * cos($bearing));
        $lng2 = deg2rad($lng) + atan2(
            sin($bearing) * sin($meters / $r) * cos(deg2rad($lat)),
            cos($meters / $r) - sin(deg2rad($lat)) * sin($lat2)
        );

        return [round(rad2deg($lat2), 7), round(rad2deg($lng2), 7)];
    }
}
