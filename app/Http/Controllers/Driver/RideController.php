<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\CustomPlace;
use App\Models\Driver;
use App\Models\DriverSession;
use App\Models\Recharge;
use App\Models\Ride;
use App\Models\Setting;
use App\Services\Dispatch;
use App\Services\Fare;
use App\Services\Routing;
use App\Services\WebPushSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RideController extends Controller
{
    private function driver(Request $request): Driver
    {
        return $request->attributes->get('driver');
    }

    /* ============ Conexión y ubicación ============ */

    /** Conectarse (Disponible) o desconectarse. No se puede desconectar en pleno viaje. */
    public function connect(Request $request)
    {
        $driver = $this->driver($request);
        $d = $request->validate([
            'online' => ['required', 'boolean'],
            'lat'    => ['nullable', 'numeric'],
            'lng'    => ['nullable', 'numeric'],
        ]);

        if ($d['online']) {
            if (! $driver->canReceiveRides()) {
                return response()->json([
                    'message' => $driver->account_status !== 'activo'
                        ? 'Tu cuenta no está activa. Comunícate con la central.'
                        : 'Tu saldo no alcanza para la comisión. Recarga para conectarte.',
                ], 422);
            }
            $upd = ['status' => 'disponible', 'last_active_at' => now()];
            if (isset($d['lat'], $d['lng'])) { $upd['lat'] = $d['lat']; $upd['lng'] = $d['lng']; }
            $driver->update($upd);
            $this->openSession($driver);
        } else {
            if ($driver->activeRide()) {
                return response()->json(['message' => 'Termina o cancela tu viaje antes de desconectarte.'], 422);
            }
            $driver->update(['status' => 'desconectado']);
            $this->closeSession($driver);
        }

        return response()->json(['ok' => true, 'status' => $driver->status, 'can_receive' => $driver->canReceiveRides()]);
    }

    /** Reporta la ubicación del conductor (para el radio de despacho y el mapa del pasajero). */
    public function location(Request $request)
    {
        $driver = $this->driver($request);
        $d = $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
        ]);

        $driver->update(['lat' => $d['lat'], 'lng' => $d['lng'], 'last_active_at' => now()]);

        return response()->json(['ok' => true, 'saldo' => (float) $driver->saldo, 'can_receive' => $driver->canReceiveRides()]);
    }

    /** Guarda la suscripción push del navegador del conductor. */
    public function subscribePush(Request $request)
    {
        $driver = $this->driver($request);
        $ok = WebPushSender::store('driver', $driver->id, $request->all());

        return response()->json(['ok' => $ok]);
    }

    /** Registra el token FCM del dispositivo (app nativa de Play Store). */
    public function subscribeFcm(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate(['token' => ['required', 'string', 'max:512']]);
        $ok = \App\Services\FcmSender::store('driver', $driver->id, $data['token']);

        return response()->json(['ok' => $ok]);
    }

    /**
     * Recalcular la ruta desde la posición actual del conductor hacia el objetivo del tramo
     * (recojo si va a buscar al pasajero; destino si ya lo lleva a bordo). Se usa cuando el
     * conductor se desvía de la línea trazada. Reutiliza OSRM (con respaldo) del servidor.
     */
    public function reroute(Request $request)
    {
        $driver = $this->driver($request);
        $d = $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
        ]);

        $ride = $driver->activeRide();
        if (! $ride || ! in_array($ride->status, ['aceptado', 'en_camino', 'llego', 'a_bordo'], true)) {
            return response()->json(['ok' => false], 409);
        }

        $toDest = $ride->status === 'a_bordo';
        $tLat = $toDest ? (float) $ride->dest_lat : (float) $ride->origin_lat;
        $tLng = $toDest ? (float) $ride->dest_lng : (float) $ride->origin_lng;

        $route = Routing::route((float) $d['lat'], (float) $d['lng'], $tLat, $tLng);

        return response()->json([
            'ok'         => true,
            'leg'        => $toDest ? 'trip' : 'pickup',
            'geometry'   => $route['geometry'],
            'distance_m' => $route['distance_m'],
            'duration_s' => $route['duration_s'],
        ]);
    }

    /* ============ Solicitudes entrantes ============ */

    /** Viajes cercanos esperando conductor (los que el conductor puede aceptar). */
    public function pending(Request $request)
    {
        $driver = $this->driver($request);

        // Solo recibe alertas si está disponible, activo y con saldo
        if ($driver->status !== 'disponible' || ! $driver->canReceiveRides() || $driver->lat === null) {
            return response()->json(['requests' => []]);
        }

        // Si ya tiene un viaje activo, no ofrecer nuevos
        if ($driver->activeRide()) {
            return response()->json(['requests' => []]);
        }

        // Omisiones TEMPORALES por conductor: mapa [ride_id => epoch en que lo descartó].
        // Una omisión solo cuenta si ocurrió DESPUÉS de la última (re)emisión del viaje,
        // así un viaje reasignado (el pasajero rechazó a otro chofer) vuelve a sonarle.
        $dismissed = (array) $request->session()->get('dismissed_map', []);

        $rides = Ride::where('status', 'solicitando')
            ->whereNull('driver_id')
            ->where('is_demo', false)
            ->where('requested_at', '>=', now()->subMinutes(3))
            ->latest('id')
            ->get();

        $out = [];
        foreach ($rides as $ride) {
            // no volver a ofrecer un viaje que el pasajero ya rechazó a este conductor (permanente)
            if (in_array($driver->id, (array) $ride->excluded_driver_ids, true)) {
                continue;
            }
            // omisión temporal: solo si descartó tras la última (re)emisión de este viaje
            $dAt = $dismissed[$ride->id] ?? null;
            if ($dAt !== null && $dAt >= $ride->requested_at->timestamp) {
                continue;
            }
            // radio que se expande con la espera (5 km base → hasta 10 km)
            $waited = max(0, now()->timestamp - $ride->requested_at->timestamp);
            $radiusKm = Dispatch::radiusForWait($waited);
            $toPickup = Routing::haversine((float) $driver->lat, (float) $driver->lng, (float) $ride->origin_lat, (float) $ride->origin_lng);
            if ($toPickup > $radiusKm * 1000) {
                continue;
            }
            $out[] = [
                'code'                => $ride->code,
                'to_pickup_m'         => round($toPickup),
                'trip_distance_m'     => $ride->distance_m,
                'trip_duration_s'     => $ride->duration_s,
                'offered_price'       => (float) $ride->offered_price,
                'suggested_price'     => (float) $ride->suggested_price,
                'payment_method'      => $ride->payment_method,
                'origin'              => ['lat' => (float) $ride->origin_lat, 'lng' => (float) $ride->origin_lng, 'address' => $ride->origin_address],
                'dest'                => ['lat' => (float) $ride->dest_lat, 'lng' => (float) $ride->dest_lng, 'address' => $ride->dest_address],
                'origin_zone'         => CustomPlace::zoneAt((float) $ride->origin_lat, (float) $ride->origin_lng),
                'dest_zone'           => CustomPlace::zoneAt((float) $ride->dest_lat, (float) $ride->dest_lng),
                'route_trip'          => $ride->route_trip, // para dibujar la ruta en la tarjeta de oferta
                'reference'           => $ride->reference,
                'passenger'           => $this->passengerCard($ride),
            ];
        }

        usort($out, fn ($a, $b) => $a['to_pickup_m'] <=> $b['to_pickup_m']);

        return response()->json([
            'requests'       => $out,
            'commission_pct' => Fare::commissionPct(),
            'currency'       => Setting::get('currency', 'S/'),
        ]);
    }

    /** El conductor acepta una solicitud (gana el primero que acepta). */
    public function accept(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate(['code' => ['required', 'string']]);

        if (! $driver->canReceiveRides()) {
            return response()->json(['message' => 'No puedes aceptar viajes ahora (revisa tu saldo o estado).'], 422);
        }
        if ($driver->activeRide()) {
            return response()->json(['message' => 'Ya tienes un viaje en curso.'], 422);
        }

        $ride = DB::transaction(function () use ($data, $driver) {
            $ride = Ride::where('code', $data['code'])
                ->where('status', 'solicitando')
                ->whereNull('driver_id')
                ->lockForUpdate()
                ->first();

            if (! $ride) {
                return null;
            }

            // si el pasajero ya rechazó a este conductor para este viaje, no puede re-tomarlo
            if (in_array($driver->id, (array) $ride->excluded_driver_ids, true)) {
                return null;
            }

            $toPickup = Routing::route((float) $driver->lat, (float) $driver->lng, (float) $ride->origin_lat, (float) $ride->origin_lng);

            // El conductor "ofrece" el viaje; el pasajero debe confirmarlo (15s).
            $ride->forceFill([
                'driver_id'       => $driver->id,
                'status'          => 'ofrecido',
                'offered_at'      => now(),
                'accepted_at'     => now(),
                'route_to_pickup' => $toPickup['geometry'],
                'is_demo'         => false,
            ])->save();

            $driver->update(['status' => 'ocupado']);

            return $ride;
        });

        if (! $ride) {
            return response()->json(['message' => 'Ese viaje ya fue tomado por otro conductor.'], 409);
        }

        $driver->increment('stat_accepted'); // para la tasa de aceptación

        // Avisar por push al pasajero que un conductor lo ofreció (debe confirmar).
        defer(fn () => WebPushSender::toOwner('passenger', (int) $ride->passenger_id, [
            'title' => '¡Conductor encontrado! 🚕',
            'body'  => 'Un conductor quiere tu viaje. Toca para confirmar.',
            'url'   => '/app',
            'tag'   => 'ride-offer',
        ]));

        return response()->json(['ok' => true, 'ride' => $this->payload($ride)]);
    }

    /** Rechazar/omitir una solicitud (no se le vuelve a mostrar a este conductor). */
    public function reject(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $this->driver($request)->increment('stat_rejected'); // para la tasa de aceptación
        $ride = Ride::where('code', $data['code'])->first();
        if ($ride) {
            // omisión temporal con marca de tiempo (ver pending): no es permanente,
            // si el viaje se re-emite más tarde volverá a aparecerle.
            $dismissed = (array) $request->session()->get('dismissed_map', []);
            $dismissed[$ride->id] = now()->timestamp;
            if (count($dismissed) > 60) {
                $dismissed = array_slice($dismissed, -60, null, true);
            }
            $request->session()->put('dismissed_map', $dismissed);
        }
        return response()->json(['ok' => true]);
    }

    /* ============ Viaje en curso ============ */

    public function current(Request $request)
    {
        $driver = $this->driver($request);
        $ride = $driver->activeRide();

        // si ofreció un viaje y el pasajero no confirmó a tiempo, se libera y queda disponible
        if ($ride && $ride->status === 'ofrecido' && Dispatch::releaseOfferIfExpired($ride)) {
            $ride = $driver->fresh()->activeRide();
        }

        if (! $ride) {
            // ¿Terminó recién? mostrar la pantalla de fin/calificación una vez
            $recent = $driver->rides()
                ->whereIn('status', ['completado', 'cancelado'])
                ->where('updated_at', '>=', now()->subMinutes(2))
                ->first();
            if ($recent && $request->session()->get('drv_ack_ride') != $recent->id) {
                return response()->json(['ride' => $this->payload($recent)]);
            }
            return response()->json(['ride' => null]);
        }

        return response()->json(['ride' => $this->payload($ride)]);
    }

    /** Confirmación de que el pasajero ya vio la pantalla final (para no repetirla). */
    public function ack(Request $request)
    {
        $request->session()->put('drv_ack_ride', $request->input('ride_id'));
        return response()->json(['ok' => true]);
    }

    /** El conductor reporta el motivo de una cancelación hecha por el pasajero (auditoría/soporte). */
    public function cancelReport(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate([
            'ride_id' => ['required', 'integer'],
            'reason'  => ['nullable', 'string', 'max:120'],
        ]);

        $ride = $driver->rides()->where('id', $data['ride_id'])->where('status', 'cancelado')->first();
        if ($ride && ! empty($data['reason'])) {
            $ride->update([
                'driver_report'      => $data['reason'],
                'driver_reported_at' => now(),
            ]);
        }
        // marcar como visto para no repetir la pantalla de cancelación
        $request->session()->put('drv_ack_ride', $data['ride_id']);

        return response()->json(['ok' => true]);
    }

    /* ============ Chat con el pasajero ============ */

    private function chatRide(Driver $driver): ?Ride
    {
        $ride = $driver->activeRide();
        return ($ride && in_array($ride->status, ['aceptado', 'en_camino', 'llego', 'a_bordo'], true)) ? $ride : null;
    }

    public function messages(Request $request)
    {
        $ride = $this->chatRide($this->driver($request));
        if (! $ride) {
            return response()->json(['messages' => []]);
        }
        $after = (int) $request->query('after', 0);
        $msgs = $ride->messages()->where('id', '>', $after)->orderBy('id')->get()
            ->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'mine' => $m->sender === 'conductor', 'time' => $m->created_at->format('H:i')]);

        return response()->json(['messages' => $msgs]);
    }

    public function sendMessage(Request $request)
    {
        $ride = $this->chatRide($this->driver($request));
        if (! $ride) {
            return response()->json(['message' => 'No hay un viaje activo con pasajero.'], 422);
        }
        $d = $request->validate(['body' => ['required', 'string', 'max:500']]);
        $m = $ride->messages()->create(['sender' => 'conductor', 'body' => trim($d['body'])]);

        return response()->json(['ok' => true, 'msg' => ['id' => $m->id, 'body' => $m->body, 'mine' => true, 'time' => $m->created_at->format('H:i')]]);
    }

    public function arrive(Request $request)
    {
        $ride = $this->requireActive($request, ['en_camino', 'aceptado']);
        if (! $ride) {
            return response()->json(['message' => 'No hay un viaje para marcar como llegado.'], 422);
        }
        $ride->forceFill(['status' => 'llego', 'arrived_at' => $ride->arrived_at ?? now()])->save();

        // Avisar por push al pasajero que su taxi llegó.
        defer(fn () => WebPushSender::toOwner('passenger', (int) $ride->passenger_id, [
            'title' => 'Tu taxi llegó 🚕',
            'body'  => 'Tu conductor está en el punto de recojo.',
            'url'   => '/app',
            'tag'   => 'ride-arrived',
        ]));

        return response()->json(['ok' => true, 'ride' => $this->payload($ride)]);
    }

    public function start(Request $request)
    {
        $ride = $this->requireActive($request, ['llego', 'en_camino']);
        if (! $ride) {
            return response()->json(['message' => 'No hay un viaje para iniciar.'], 422);
        }
        $ride->forceFill(['status' => 'a_bordo', 'started_at' => $ride->started_at ?? now()])->save();
        return response()->json(['ok' => true, 'ride' => $this->payload($ride)]);
    }

    public function complete(Request $request)
    {
        $driver = $this->driver($request);
        $ride = $this->requireActive($request, ['a_bordo', 'llego']);
        if (! $ride) {
            return response()->json(['message' => 'No hay un viaje para finalizar.'], 422);
        }

        $finalPrice = (float) $ride->offered_price;
        $commission = Fare::commission($finalPrice);

        $ride->forceFill([
            'status'       => 'completado',
            'started_at'   => $ride->started_at ?? now(),
            'completed_at' => now(),
            'final_price'  => $finalPrice,
            'commission'   => $commission,
        ])->save();

        // Descuenta la comisión del saldo del conductor
        $driver->applyMovement('comision', -$commission, "Comisión viaje {$ride->code}", 'ride', $ride->id);
        $driver->increment('total_trips');
        $driver->update(['status' => 'disponible']);
        if ($p = $ride->passenger) {
            $p->increment('total_trips');
        }

        return response()->json([
            'ok'    => true,
            'ride'  => $this->payload($ride->fresh()),
            'saldo' => (float) $driver->fresh()->saldo,
        ]);
    }

    public function cancel(Request $request)
    {
        $driver = $this->driver($request);
        $ride = $driver->activeRide();
        if (! $ride) {
            return response()->json(['message' => 'No tienes un viaje activo.'], 422);
        }

        $ride->forceFill([
            'status'        => 'cancelado',
            'cancelled_by'  => 'conductor',
            'cancel_reason' => $request->input('reason'),
            'cancelled_at'  => now(),
        ])->save();

        $driver->update(['status' => 'disponible']);

        return response()->json(['ok' => true]);
    }

    public function ratePassenger(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate([
            'code'   => ['required', 'string'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $ride = Ride::where('code', $data['code'])
            ->where('driver_id', $driver->id)
            ->where('status', 'completado')
            ->firstOrFail();

        if ($ride->rating_to_passenger === null) {
            $ride->update(['rating_to_passenger' => $data['rating']]);

            if ($p = $ride->passenger) {
                $avg = Ride::where('passenger_id', $p->id)
                    ->whereNotNull('rating_to_passenger')
                    ->avg('rating_to_passenger');
                if ($avg) {
                    $p->update(['rating' => round($avg, 2)]);
                }
            }
        }

        $request->session()->put('drv_ack_ride', $ride->id);

        return response()->json(['ok' => true]);
    }

    /* ============ Saldo y recargas ============ */

    public function saldo(Request $request)
    {
        $driver = $this->driver($request);
        $moves = $driver->movements()->take(30)->get()->map(fn ($m) => [
            'type'   => $m->type,
            'label'  => $this->moveLabel($m->type),
            'amount' => (float) $m->amount,
            'balance'=> (float) $m->balance_after,
            'desc'   => $m->description,
            'date'   => $m->created_at->format('d/m/Y H:i'),
        ]);

        return response()->json([
            'saldo'          => (float) $driver->saldo,
            'commission_pct' => Fare::commissionPct(),
            'min_saldo'      => Fare::minSaldo(),
            'min_alert'      => (float) Setting::get('min_saldo_alert', 5.00),
            'can_receive' => $driver->canReceiveRides(),
            'currency'    => Setting::get('currency', 'S/'),
            'yape_number' => Setting::get('yape_number', ''),
            'yape_holder' => Setting::get('yape_holder', ''),
            'tiers'       => array_values(array_filter(array_map('trim', explode(',', (string) Setting::get('saldo_tiers', '20,50,100'))))),
            'movements'   => $moves,
            'pending'     => $driver->recharges()->where('status', 'pendiente')->latest()->get()->map(fn ($r) => [
                'amount' => (float) $r->amount,
                'method' => $r->methodLabel(),
                'date'   => $r->created_at->format('d/m/Y H:i'),
            ]),
        ]);
    }

    /** El conductor solicita una recarga (queda pendiente hasta que el admin la valide). */
    public function recharge(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'min:1', 'max:1000'],
            'method'    => ['required', 'in:yape,transferencia'],
            'reference' => ['nullable', 'string', 'max:60'],
        ]);

        $recharge = Recharge::create([
            'driver_id' => $driver->id,
            'amount'    => round($data['amount'], 2),
            'method'    => $data['method'],
            'reference' => $data['reference'] ?? null,
            'status'    => 'pendiente',
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Recarga enviada. La central la validará y se acreditará tu saldo.',
            'code'    => $recharge->id,
        ]);
    }

    public function history(Request $request)
    {
        $driver = $this->driver($request);
        $rides = $driver->rides()->take(30)->get()->map(fn (Ride $r) => [
            'code'   => $r->code,
            'status' => $r->status,
            'status_label' => $r->statusLabel(),
            'origin' => $r->origin_address,
            'dest'   => $r->dest_address,
            'price'  => (float) ($r->final_price ?? $r->offered_price),
            'method' => $r->payment_method,
            'date'   => $r->created_at->format('d/m/Y H:i'),
        ]);

        $today = $driver->rides()
            ->where('status', 'completado')
            ->whereDate('completed_at', now()->toDateString())
            ->get();

        return response()->json([
            'rides'    => $rides,
            'currency' => Setting::get('currency', 'S/'),
            'today'    => [
                'trips'    => $today->count(),
                'earnings' => (float) $today->sum('final_price'),
            ],
        ]);
    }

    /** Estadísticas del conductor para el panel de inicio. */
    public function stats(Request $request)
    {
        $driver = $this->driver($request);
        $today = now()->toDateString();

        $todayRides = $driver->rides()
            ->where('status', 'completado')
            ->whereDate('completed_at', $today)
            ->get();

        // ganancias netas de hoy = tarifa cobrada menos la comisión de cada viaje
        $earnings = $todayRides->sum(fn (Ride $r) => (float) ($r->final_price ?? $r->offered_price) - (float) ($r->commission ?? 0));

        $accepted = (int) $driver->stat_accepted;
        $rejected = (int) $driver->stat_rejected;
        $total = $accepted + $rejected;

        return response()->json([
            'saldo'           => (float) $driver->saldo,
            'rating'          => (float) $driver->rating,
            'trips_total'     => (int) $driver->total_trips,
            'today_trips'     => $todayRides->count(),
            'today_earnings'  => round($earnings, 2),
            'hours_online'    => round($this->hoursOnlineToday($driver), 1),
            'acceptance_rate' => $total > 0 ? (int) round($accepted / $total * 100) : null,
            'currency'        => Setting::get('currency', 'S/'),
        ]);
    }

    /** Horas conectado HOY (suma de sesiones de hoy; la abierta cuenta hasta ahora o la última actividad). */
    private function hoursOnlineToday(Driver $driver): float
    {
        $start = now()->startOfDay();
        $sessions = DriverSession::where('driver_id', $driver->id)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start))
            ->get();

        $seconds = 0;
        foreach ($sessions as $s) {
            $from = $s->started_at->greaterThan($start) ? $s->started_at : $start;
            if ($s->ended_at) {
                $to = $s->ended_at;
            } else {
                // sesión abierta: hasta ahora si sigue activo, o hasta su última actividad si dejó de reportar
                $to = ($driver->status !== 'desconectado' && $driver->last_active_at && $driver->last_active_at->greaterThan(now()->subMinutes(5)))
                    ? now()
                    : ($driver->last_active_at ?? $s->started_at);
            }
            $seconds += max(0, $to->getTimestamp() - $from->getTimestamp());
        }

        return $seconds / 3600;
    }

    /** Abre una sesión de conexión (cerrando cualquiera abierta por seguridad). */
    private function openSession(Driver $driver): void
    {
        DriverSession::where('driver_id', $driver->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        DriverSession::create(['driver_id' => $driver->id, 'started_at' => now()]);
    }

    /** Cierra la sesión abierta del conductor. */
    private function closeSession(Driver $driver): void
    {
        DriverSession::where('driver_id', $driver->id)->whereNull('ended_at')->update(['ended_at' => now()]);
    }

    /* ============ Helpers ============ */

    private function requireActive(Request $request, array $fromStates): ?Ride
    {
        $ride = $this->driver($request)->activeRide();
        if (! $ride || ! in_array($ride->status, $fromStates, true)) {
            return null;
        }
        return $ride;
    }

    private function passengerCard(Ride $ride): array
    {
        $p = $ride->passenger;
        return [
            'name'    => $p ? $p->name : 'Pasajero',
            'rating'  => $p ? (float) $p->rating : 5.0,
            'trips'   => $p ? $p->total_trips : 0,
            'initial' => $p ? mb_strtoupper(mb_substr($p->name, 0, 1)) : 'P',
        ];
    }

    private function payload(Ride $ride): array
    {
        return [
            'id'           => $ride->id,
            'code'         => $ride->code,
            'status'       => $ride->status,
            'status_label' => $this->driverStatusLabel($ride->status),
            'cancelled_by' => $ride->cancelled_by,
            'offered_price'=> (float) $ride->offered_price,
            'final_price'  => $ride->final_price !== null ? (float) $ride->final_price : null,
            'commission'   => $ride->commission !== null ? (float) $ride->commission : Fare::commission((float) $ride->offered_price),
            'payment_method' => $ride->payment_method,
            'distance_m'   => $ride->distance_m,
            'duration_s'   => $ride->duration_s,
            'origin'       => ['lat' => (float) $ride->origin_lat, 'lng' => (float) $ride->origin_lng, 'address' => $ride->origin_address],
            'dest'         => ['lat' => (float) $ride->dest_lat, 'lng' => (float) $ride->dest_lng, 'address' => $ride->dest_address],
            'reference'    => $ride->reference,
            'route_to_pickup' => $ride->route_to_pickup,
            'route_trip'   => $ride->route_trip,
            'last_message_id' => (int) $ride->messages()->max('id'),
            'passenger'    => $this->passengerCard($ride),
            'currency'     => Setting::get('currency', 'S/'),
        ];
    }

    private function driverStatusLabel(string $status): string
    {
        return [
            'aceptado'   => 'Ve a recoger al pasajero',
            'en_camino'  => 'Ve a recoger al pasajero',
            'llego'      => 'Esperando al pasajero',
            'a_bordo'    => 'Viaje en curso',
            'completado' => 'Viaje completado',
            'cancelado'  => 'Viaje cancelado',
        ][$status] ?? $status;
    }

    private function moveLabel(string $type): string
    {
        return [
            'recarga'  => 'Recarga',
            'comision' => 'Comisión de viaje',
            'ajuste'   => 'Ajuste',
        ][$type] ?? $type;
    }
}
