<?php

namespace App\Http\Controllers\Passenger;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Passenger;
use App\Models\Ride;
use App\Models\Setting;
use App\Services\DemoSim;
use App\Services\Dispatch;
use App\Services\Fare;
use App\Services\Routing;
use App\Services\WebPushSender;
use Illuminate\Http\Request;

class RideController extends Controller
{
    private function passenger(Request $request): Passenger
    {
        return $request->attributes->get('passenger');
    }

    /** Calcula ruta + precio sugerido (sin crear el viaje). */
    public function quote(Request $request)
    {
        $d = $request->validate([
            'origin_lat' => ['required', 'numeric'],
            'origin_lng' => ['required', 'numeric'],
            'dest_lat'   => ['required', 'numeric'],
            'dest_lng'   => ['required', 'numeric'],
        ]);

        $route = Routing::route($d['origin_lat'], $d['origin_lng'], $d['dest_lat'], $d['dest_lng']);
        $suggested = Fare::suggest($route['distance_m'], $route['duration_s']);

        return response()->json([
            'distance_m' => $route['distance_m'],
            'duration_s' => $route['duration_s'],
            'geometry'   => $route['geometry'],
            'suggested'  => $suggested,
            'floor'      => Fare::floor($suggested),
            'approach'   => $this->approachEstimate((float) $d['origin_lat'], (float) $d['origin_lng']),
            'currency'   => Setting::get('currency', 'S/'),
        ]);
    }

    /**
     * Estimación del costo de aproximación para mostrar ANTES de pedir el viaje.
     *
     * El monto real depende del conductor que termine tomando la carrera, y ese aún no existe.
     * Así que se estima con el conductor libre MÁS CERCANO en este momento: es el mejor caso
     * realista y el que casi siempre acepta primero (el despacho ordena por cercanía).
     * El pasajero ve el monto definitivo en la pantalla de confirmación, antes de aceptar.
     */
    private function approachEstimate(float $lat, float $lng): array
    {
        $rules = Fare::approachRules();

        if (! $rules['enabled']) {
            return ['enabled' => false, 'fee' => 0.0, 'distance_m' => null] + $rules;
        }

        $nearest = null;
        foreach (Dispatch::eligibleDrivers($lat, $lng, (float) Setting::get('dispatch_radius_max_km', 10.0)) as $e) {
            if ($e['driver']->is_demo) {
                continue;
            }
            // eligibleDrivers mide en línea recta; para el precio se usa el mismo criterio
            // que la tarjeta del conductor y que accept(), o el estimado no cuadraría.
            $nearest = Fare::approachDistance($lat, $lng, (float) $e['driver']->lat, (float) $e['driver']->lng);
            break; // eligibleDrivers ya viene ordenado por cercanía
        }

        // Sin conductores conectados no hay una distancia real que estimar: se muestra solo
        // el tramo A→B y se avisa que el recojo se suma cuando un conductor tome el viaje.
        return [
            'enabled'    => true,
            'fee'        => $nearest !== null ? Fare::approach($nearest) : 0.0,
            'distance_m' => $nearest !== null ? (int) round($nearest) : null,
        ] + $rules;
    }

    /** Crea la solicitud de viaje. */
    public function store(Request $request)
    {
        $passenger = $this->passenger($request);

        // Una búsqueda vencida no puede bloquear un pedido nuevo: si el pasajero cerró la app
        // mientras buscaba, al volver a pedir se cierra sola y puede seguir.
        Dispatch::expireStaleSearches();

        if ($passenger->activeRide()) {
            return response()->json(['message' => 'Ya tienes un viaje en curso.'], 422);
        }

        $d = $request->validate([
            'origin_lat' => ['required', 'numeric'],
            'origin_lng' => ['required', 'numeric'],
            'origin_address' => ['nullable', 'string', 'max:180'],
            'reference'  => ['nullable', 'string', 'max:200'],
            'dest_lat'   => ['required', 'numeric'],
            'dest_lng'   => ['required', 'numeric'],
            'dest_address' => ['nullable', 'string', 'max:180'],
            'offered_price' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'in:efectivo,yape'],
        ]);

        $route = Routing::route($d['origin_lat'], $d['origin_lng'], $d['dest_lat'], $d['dest_lng']);
        $suggested = Fare::suggest($route['distance_m'], $route['duration_s']);

        $ride = Ride::create([
            'code'           => Ride::makeCode(),
            'passenger_id'   => $passenger->id,
            'origin_lat'     => $d['origin_lat'],
            'origin_lng'     => $d['origin_lng'],
            'origin_address' => $d['origin_address'] ?? null,
            'reference'      => $d['reference'] ?? null,
            'dest_lat'       => $d['dest_lat'],
            'dest_lng'       => $d['dest_lng'],
            'dest_address'   => $d['dest_address'] ?? null,
            'distance_m'     => $route['distance_m'],
            'duration_s'     => $route['duration_s'],
            'suggested_price'=> $suggested,
            'offered_price'  => round($d['offered_price'], 2),
            'payment_method' => $d['payment_method'],
            'route_trip'     => $route['geometry'],
            'status'         => 'solicitando',
            'requested_at'   => now(),
        ]);

        // Avisar por push a los conductores cercanos elegibles (tras responder, sin demorar al pasajero).
        defer(fn () => Dispatch::notifyNearbyDrivers($ride));

        return response()->json(['ok' => true, 'ride' => ['code' => $ride->code]]);
    }

    /** Estado del viaje activo + posición del conductor en vivo (para hacer polling). */
    public function current(Request $request)
    {
        $passenger = $this->passenger($request);

        // Cierra las búsquedas que ya pasaron del límite (la suya incluida) antes de contestar.
        Dispatch::expireStaleSearches();

        $ride = $passenger->activeRide();

        if (! $ride) {
            // ¿Un viaje que acaba de terminar y el pasajero aún no ve el resultado?
            $recent = $passenger->rides()
                ->whereIn('status', ['completado', 'cancelado', 'sin_conductor'])
                ->where('updated_at', '>=', now()->subMinutes(2))
                ->first();
            if ($recent && $request->session()->get('pax_ack_ride') != $recent->id) {
                return response()->json(['ride' => $this->payload($recent, null)]);
            }
            return response()->json(['ride' => null]);
        }

        // Oferta esperando confirmación del pasajero (Aceptar conductor / Buscar otro)
        if ($ride->status === 'ofrecido') {
            if (! Dispatch::releaseOfferIfExpired($ride)) {
                return response()->json(['ride' => $this->payload($ride->fresh('driver'), $this->driverPosArr($ride), $this->offerInfo($ride))]);
            }
            $ride->refresh(); // venció sin respuesta → volvió a 'solicitando', seguimos buscando abajo
        }

        // Búsqueda de conductor
        if ($ride->status === 'solicitando') {
            $excluded = (array) $ride->excluded_driver_ids;

            // ¿Hay conductores REALES conectados y elegibles (no rechazados) para este viaje?
            // Mismo radio expansivo que usa la app del conductor (coherencia).
            $waited = max(0, now()->getTimestamp() - $ride->requested_at->getTimestamp());
            $radius = Dispatch::radiusForWait($waited);
            $realOnline = false;
            foreach (Dispatch::eligibleDrivers((float) $ride->origin_lat, (float) $ride->origin_lng, $radius, $excluded) as $e) {
                if (! $e['driver']->is_demo) { $realOnline = true; break; }
            }

            // Si hay conductores reales en línea, esperamos a que uno ofrezca desde su app.
            if ($realOnline) {
                return response()->json(['ride' => $this->payload($ride, null), 'search' => $this->searchInfo($ride)]);
            }

            // Sin conductores reales: conductor de prueba (si está habilitado). También pasa por confirmación.
            if ((string) Setting::get('demo_enabled', '1') === '1') {
                $delay = (int) Setting::get('search_delay_s', 3);
                $waited = now()->getTimestamp() - $ride->requested_at->getTimestamp();

                if ($waited >= $delay && $this->assignDemoDriver($ride)) {
                    return response()->json(['ride' => $this->payload($ride->fresh('driver'), $this->driverPosArr($ride), $this->offerInfo($ride))]);
                }
            }

            return response()->json(['ride' => $this->payload($ride, null), 'search' => $this->searchInfo($ride)]);
        }

        // Viaje asignado / en curso → el conductor demo avanza según el tiempo transcurrido
        $pos = null;
        if ($ride->is_demo && in_array($ride->status, ['aceptado', 'en_camino', 'llego', 'a_bordo'], true)) {
            $pos = DemoSim::advance($ride);
        } elseif ($ride->driver) {
            $pos = ['lat' => (float) $ride->driver->lat, 'lng' => (float) $ride->driver->lng];
        }

        return response()->json(['ride' => $this->payload($ride->fresh('driver'), $pos)]);
    }

    /** Guarda la suscripción push del navegador del pasajero. */
    public function subscribePush(Request $request)
    {
        $passenger = $this->passenger($request);
        $ok = WebPushSender::store('passenger', $passenger->id, $request->all());

        return response()->json(['ok' => $ok]);
    }

    /** Registra el token FCM del dispositivo (app nativa de Play Store). */
    public function subscribeFcm(Request $request)
    {
        $passenger = $this->passenger($request);
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $ok = \App\Services\FcmSender::store('passenger', $passenger->id, $data['token']);

        return response()->json(['ok' => $ok]);
    }

    /**
     * Conductores DISPONIBLES cerca, para mostrar los carritos en el mapa del pasajero
     * (ver que hay unidades activas antes de pedir). Solo posición (sin identidad).
     */
    public function nearbyDrivers(Request $request)
    {
        $this->passenger($request);
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');
        if (! $lat || ! $lng) {
            return response()->json(['drivers' => [], 'count' => 0, 'nearest_m' => null]);
        }

        $minSaldo   = Fare::minSaldo();
        $staleS     = (int) Setting::get('driver_stale_s', 180);
        $displayKm  = (float) Setting::get('nearby_display_km', 6.0);
        $demoOn     = (string) Setting::get('demo_enabled', '1') === '1';

        $q = Driver::query()
            ->where('status', 'disponible')          // libres (los ocupados no se muestran)
            ->where('account_status', 'activo')
            ->where('saldo', '>=', $minSaldo)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->where(function ($w) use ($staleS) {
                $w->where('last_active_at', '>=', now()->subSeconds($staleS))->orWhere('is_demo', true);
            });
        if (! $demoOn) {
            $q->where('is_demo', false);
        }

        $out = [];
        $nearest = null;
        foreach ($q->get() as $d) {
            $dist = Routing::haversine($lat, $lng, (float) $d->lat, (float) $d->lng);
            if ($dist > $displayKm * 1000) {
                continue;
            }
            $out[] = ['id' => $d->id, 'lat' => (float) $d->lat, 'lng' => (float) $d->lng, 'dist' => $dist];
            if ($nearest === null || $dist < $nearest) {
                $nearest = $dist;
            }
        }

        usort($out, fn ($a, $b) => $a['dist'] <=> $b['dist']);
        $out = array_slice($out, 0, 30);
        // no exponer la distancia exacta en el payload final
        $out = array_map(fn ($x) => ['id' => $x['id'], 'lat' => $x['lat'], 'lng' => $x['lng']], $out);

        return response()->json([
            'drivers'   => $out,
            'count'     => count($out),
            'nearest_m' => $nearest !== null ? (int) round($nearest) : null,
        ]);
    }

    private function assignDemoDriver(Ride $ride): bool
    {
        $driver = Dispatch::demoDriver((float) $ride->origin_lat, (float) $ride->origin_lng);
        if (in_array($driver->id, (array) $ride->excluded_driver_ids, true)) {
            return false; // el pasajero ya rechazó al conductor de prueba
        }
        $toPickup  = Routing::route((float) $driver->lat, (float) $driver->lng, (float) $ride->origin_lat, (float) $ride->origin_lng);
        $approachM = Fare::approachDistance((float) $driver->lat, (float) $driver->lng, (float) $ride->origin_lat, (float) $ride->origin_lng);

        $ride->forceFill([
            'driver_id'       => $driver->id,
            'status'          => 'ofrecido',
            'offered_at'      => now(),
            'accepted_at'     => now(),
            'route_to_pickup' => $toPickup['geometry'],
            // mismo cálculo que un conductor real, para que la prueba refleje el precio final
            'approach_m'      => (int) round($approachM),
            'approach_fee'    => Fare::approach($approachM),
            'is_demo'         => true,
        ])->save();

        $driver->update(['status' => 'ocupado']);

        return true;
    }

    /** El pasajero confirma al conductor ofrecido → el viaje queda en firme. */
    public function confirmDriver(Request $request)
    {
        $passenger = $this->passenger($request);
        $ride = $passenger->activeRide();

        if (! $ride || $ride->status !== 'ofrecido') {
            return response()->json(['message' => 'La oferta ya no está disponible.'], 422);
        }
        if (Dispatch::releaseOfferIfExpired($ride)) {
            return response()->json(['message' => 'Se acabó el tiempo. Buscando otro conductor…'], 409);
        }

        $ride->forceFill(['status' => 'en_camino', 'offered_at' => null])->save();

        return response()->json(['ok' => true, 'ride' => $this->payload($ride->fresh('driver'), $this->driverPosArr($ride))]);
    }

    /** El pasajero rechaza al conductor ofrecido → se busca a otro (se excluye a este). */
    public function rejectDriver(Request $request)
    {
        $passenger = $this->passenger($request);
        $ride = $passenger->activeRide();

        if (! $ride || $ride->status !== 'ofrecido') {
            return response()->json(['message' => 'No hay una oferta para rechazar.'], 422);
        }

        Dispatch::releaseOffer($ride);

        return response()->json(['ok' => true, 'ride' => $this->payload($ride->fresh(), null)]);
    }

    /** Posición actual del conductor (para el mapa). */
    private function driverPosArr(Ride $ride): ?array
    {
        $d = $ride->driver;
        if (! $d || $d->lat === null) {
            return null;
        }
        return ['lat' => (float) $d->lat, 'lng' => (float) $d->lng];
    }

    /** Datos de la oferta: segundos restantes + ETA al recojo. */
    /** Cuánto le queda a la búsqueda, para que el pasajero vea que tiene fin. */
    private function searchInfo(Ride $ride): array
    {
        return [
            'seconds_left' => Dispatch::searchSecondsLeft($ride),
            'timeout'      => Dispatch::searchTimeoutS(),
        ];
    }

    private function offerInfo(Ride $ride): array
    {
        $timeout = (int) Setting::get('offer_timeout_s', 15);
        $elapsed = $ride->offered_at ? (int) abs($ride->offered_at->diffInSeconds(now())) : 0;
        $d = $ride->driver;
        $toPickup = null;
        $eta = null;
        if ($d && $d->lat !== null) {
            $toPickup = Routing::haversine((float) $d->lat, (float) $d->lng, (float) $ride->origin_lat, (float) $ride->origin_lng);
            $eta = max(1, (int) round($toPickup / 1000 / 22 * 60)); // ~22 km/h promedio urbano
        }
        return [
            'seconds_left' => max(0, $timeout - $elapsed),
            'timeout'      => $timeout,
            'to_pickup_m'  => $toPickup !== null ? round($toPickup) : null,
            'eta_min'      => $eta,
        ];
    }

    public function cancel(Request $request)
    {
        $passenger = $this->passenger($request);
        $ride = $passenger->activeRide();

        if (! $ride) {
            return response()->json(['message' => 'No tienes un viaje activo.'], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        // ¿Fue una cancelación "tardía"? (el conductor ya estaba asignado / en camino / esperando)
        $lateCancel = in_array($ride->status, ['aceptado', 'en_camino', 'llego'], true) && $ride->driver_id;

        $ride->forceFill([
            'status'        => 'cancelado',
            'cancelled_by'  => 'pasajero',
            'cancel_reason' => $data['reason'] ?? null,
            'cancelled_at'  => now(),
        ])->save();

        $passenger->increment('cancel_count');

        // Impacto en la calificación del pasajero SOLO si canceló con el conductor ya en camino.
        // Penalización suave y recuperable (−0.10, con piso de 3.50) para no castigar en exceso.
        if ($lateCancel) {
            $newRating = max(3.50, round((float) $passenger->rating - 0.10, 2));
            $passenger->update(['rating' => $newRating]);
        }

        // Liberar al conductor asignado para que vuelva a recibir viajes de inmediato
        // (antes solo se liberaba al conductor demo; el real quedaba 'ocupado' y no recibía nada).
        if ($ride->driver && $ride->driver->status === 'ocupado') {
            $ride->driver->update(['status' => 'disponible']);
        }

        return response()->json(['ok' => true]);
    }

    public function rate(Request $request)
    {
        $d = $request->validate([
            'code'   => ['required', 'string'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $passenger = $this->passenger($request);
        $ride = Ride::where('code', $d['code'])
            ->where('passenger_id', $passenger->id)
            ->where('status', 'completado')
            ->firstOrFail();

        if ($ride->rating_to_driver === null) {
            $ride->update(['rating_to_driver' => $d['rating']]);

            // recalcula el promedio del conductor (sencillo, sobre viajes calificados)
            if ($driver = $ride->driver) {
                $avg = Ride::where('driver_id', $driver->id)
                    ->whereNotNull('rating_to_driver')
                    ->avg('rating_to_driver');
                if ($avg) {
                    $driver->update(['rating' => round($avg, 2)]);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /** El pasajero confirma que ya vio la pantalla final (para no repetirla al recargar). */
    public function ack(Request $request)
    {
        $request->session()->put('pax_ack_ride', $request->input('ride_id'));
        return response()->json(['ok' => true]);
    }

    /* ============ Chat con el conductor ============ */

    private function chatRide(Passenger $passenger): ?Ride
    {
        $ride = $passenger->activeRide();
        return ($ride && $ride->driver_id && in_array($ride->status, ['aceptado', 'en_camino', 'llego', 'a_bordo'], true)) ? $ride : null;
    }

    public function messages(Request $request)
    {
        $ride = $this->chatRide($this->passenger($request));
        if (! $ride) {
            return response()->json(['messages' => []]);
        }
        $after = (int) $request->query('after', 0);
        $msgs = $ride->messages()->where('id', '>', $after)->orderBy('id')->get()
            ->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'mine' => $m->sender === 'pasajero', 'time' => $m->created_at->format('H:i')]);

        return response()->json(['messages' => $msgs]);
    }

    public function sendMessage(Request $request)
    {
        $ride = $this->chatRide($this->passenger($request));
        if (! $ride) {
            return response()->json(['message' => 'No hay un viaje activo con conductor.'], 422);
        }
        $d = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $m = $ride->messages()->create(['sender' => 'pasajero', 'body' => trim($d['body'])]);

        return response()->json(['ok' => true, 'msg' => ['id' => $m->id, 'body' => $m->body, 'mine' => true, 'time' => $m->created_at->format('H:i')]]);
    }

    public function history(Request $request)
    {
        $passenger = $this->passenger($request);
        $rides = $passenger->rides()->take(30)->get()->map(fn (Ride $r) => [
            'code'       => $r->code,
            'status'     => $r->status,
            'status_label' => $r->statusLabel(),
            'origin'     => $r->origin_address,
            'dest'       => $r->dest_address,
            'price'      => (float) ($r->final_price ?? $r->totalPrice()),
            'method'     => $r->payment_method,
            'date'       => $r->created_at->format('d/m/Y H:i'),
        ]);

        return response()->json(['rides' => $rides, 'currency' => Setting::get('currency', 'S/')]);
    }

    private function payload(Ride $ride, ?array $pos, ?array $offer = null): array
    {
        $cur = Setting::get('currency', 'S/');
        $driver = $ride->driver;

        return [
            'id'           => $ride->id,
            'code'         => $ride->code,
            'status'       => $ride->status,
            'status_label' => $ride->statusLabel(),
            'is_demo'      => (bool) $ride->is_demo,
            'currency'     => $cur,
            'offered_price'=> (float) $ride->offered_price,
            // Costo de aproximación del conductor que tomó el viaje (0 mientras nadie lo tome).
            'approach_m'   => $ride->approach_m !== null ? (int) $ride->approach_m : null,
            'approach_fee' => (float) $ride->approach_fee,
            // Ajuste que pidió el conductor al aceptar (0 = tomó el viaje al precio ofrecido).
            'counter_offer'=> (float) $ride->counter_offer,
            'total_price'  => $ride->totalPrice(),
            'final_price'  => $ride->final_price !== null ? (float) $ride->final_price : null,
            'payment_method' => $ride->payment_method,
            'distance_m'   => $ride->distance_m,
            'duration_s'   => $ride->duration_s,
            'origin'       => ['lat' => (float) $ride->origin_lat, 'lng' => (float) $ride->origin_lng, 'address' => $ride->origin_address],
            'dest'         => ['lat' => (float) $ride->dest_lat, 'lng' => (float) $ride->dest_lng, 'address' => $ride->dest_address],
            // se devuelve para poder repetir el viaje tal cual si nadie lo tomó (renderNoDriver)
            'reference'    => $ride->reference,
            'route_to_pickup' => $ride->route_to_pickup,
            'route_trip'   => $ride->route_trip,
            'driver_pos'   => $pos,
            'offer'        => $offer,
            'last_message_id' => $ride->driver_id ? (int) $ride->messages()->max('id') : 0,
            'driver'       => $driver ? [
                'name'    => $driver->full_name,
                'vehicle' => trim(($driver->vehicle_make . ' ' . $driver->vehicle_model)),
                'plate'   => $driver->vehicle_plate,
                'color'   => $driver->vehicle_color,
                'rating'  => (float) $driver->rating,
                'trips'   => $driver->total_trips,
                'initial' => mb_strtoupper(mb_substr($driver->full_name, 0, 1)),
                // solo se envían las fotos aprobadas por la central: las columnas del conductor
                // nunca guardan una foto sin aprobar (ver App\Services\DriverPhotos)
                'photo'         => \App\Services\ImageStore::url($driver->photo_path),
                'vehicle_photo' => \App\Services\VehiclePhoto::url($driver->vehicle_photo),
            ] : null,
        ];
    }
}
