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
use App\Services\Reports;
use App\Services\ReviewerSim;
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
                        : (\App\Services\DriverPhotos::blockMessage($driver)
                            ?: 'Tu saldo no alcanza para la comisión. Recarga para conectarte.'),
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

        return response()->json([
            'ok'          => true,
            'status'      => $driver->status,
            'can_receive' => $driver->canReceiveRides(),
            'push_ok'     => $this->pushReady($driver),
        ]);
    }

    /**
     * ¿Podemos avisarle con la app cerrada?
     *
     * Se responde con lo que el SERVIDOR realmente tiene guardado, no con lo que el celular
     * cree: así queda cubierto todo — permiso denegado, token que nunca se registró, o token
     * caducado. Si esto es false, el conductor solo se entera de una carrera mirando la
     * pantalla, que es exactamente lo que hay que evitar.
     */
    private function pushReady(Driver $driver): bool
    {
        return \App\Models\FcmToken::where('owner_type', 'driver')->where('owner_id', $driver->id)->exists()
            || \App\Models\PushSubscription::where('owner_type', 'driver')->where('owner_id', $driver->id)->exists();
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

        // Cerrar de paso las búsquedas vencidas: así el viaje se da por perdido aunque el
        // pasajero haya cerrado la app (su celular ya no está sondeando para cerrarlo).
        Dispatch::expireStaleSearches();
        // Y depurar la lista de conectados: los que llevan horas sin dar señales salen solos.
        Dispatch::disconnectAbandoned();

        // La ventana es la MISMA que el límite de búsqueda del pasajero (ver Dispatch): si
        // fueran distintas, uno seguiría esperando un viaje que el otro ya no puede ver.
        // El revisor de Google ve SOLO su solicitud simulada, y los conductores reales
        // no la ven a ella: sin esto, o el revisor no tiene nada que probar (rechazo por
        // "no pudimos revisar la funcionalidad"), o le entra el viaje de un pasajero real.
        if ($driver->is_reviewer) {
            $rides = collect([ReviewerSim::ensureRequest($driver)])->filter()->values();
        } else {
            $rides = Ride::where('status', 'solicitando')
                ->whereNull('driver_id')
                ->where('is_demo', false)
                ->where('requested_at', '>=', now()->subSeconds(Dispatch::searchTimeoutS()))
                ->latest('id')
                ->get();
        }

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
            // Costo de aproximación calculado con la distancia de ESTE conductor: dos conductores
            // ven la misma carrera con distinto total, y el que viene de lejos ve pagado su recorrido.
            // Es el monto definitivo: accept() lo recalcula con la misma fórmula, no con OSRM.
            $approachM   = Fare::approachDistance((float) $driver->lat, (float) $driver->lng, (float) $ride->origin_lat, (float) $ride->origin_lng);
            $approachFee = Fare::approach($approachM);

            $out[] = [
                'code'                => $ride->code,
                'to_pickup_m'         => round($toPickup),
                'approach_m'          => (int) round($approachM),
                'approach_fee'        => $approachFee,
                'total_price'         => Fare::total((float) $ride->offered_price, $approachFee),
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
            'approach'       => Fare::approachRules(),
            'counter'        => Fare::counterRules(), // importes que puede añadir al aceptar
            'currency'       => Setting::get('currency', 'S/'),
        ]);
    }

    /** El conductor acepta una solicitud (gana el primero que acepta). */
    public function accept(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate([
            'code' => ['required', 'string'],
            // ajuste opcional que pide el conductor; se valida contra la lista de la central
            'bump' => ['nullable', 'numeric'],
        ]);

        // Fuera de la transacción: si el monto no es uno de los configurados, se cobra 0
        // (nunca se rechaza el viaje por eso — el conductor igual quiere la carrera).
        $counterOffer = Fare::counterOffer((float) ($data['bump'] ?? 0));

        if (! $driver->canReceiveRides()) {
            return response()->json(['message' => 'No puedes aceptar viajes ahora (revisa tu saldo o estado).'], 422);
        }
        if ($driver->activeRide()) {
            return response()->json(['message' => 'Ya tienes un viaje en curso.'], 422);
        }

        $ride = DB::transaction(function () use ($data, $driver, $counterOffer) {
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

            // Costo de aproximación: se fija AQUÍ, con la distancia del conductor que tomó el viaje.
            // Se mide con Fare::approachDistance (el mismo criterio que su tarjeta), NO con la
            // distancia de OSRM: así cobra exactamente el monto que vio antes de aceptar.
            $approachM   = Fare::approachDistance((float) $driver->lat, (float) $driver->lng, (float) $ride->origin_lat, (float) $ride->origin_lng);
            $approachFee = Fare::approach($approachM);

            // El conductor "ofrece" el viaje; el pasajero debe confirmarlo (15s).
            // Salvo el revisor de Google: del otro lado hay un pasajero simulado que no
            // va a confirmar nunca, así que su viaje pasa directo a 'aceptado' y puede
            // seguir con llegué → iniciar → finalizar. Y conserva is_demo para que no
            // se cuele en las estadísticas reales (ver Ride::real()).
            $isReviewer = (bool) $driver->is_reviewer;

            $ride->forceFill([
                'driver_id'       => $driver->id,
                'status'          => $isReviewer ? 'aceptado' : 'ofrecido',
                'offered_at'      => now(),
                'accepted_at'     => now(),
                'route_to_pickup' => $toPickup['geometry'],
                'approach_m'      => (int) round($approachM),
                'approach_fee'    => $approachFee,
                'counter_offer'   => $counterOffer,
                'is_demo'         => $isReviewer,
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
    /**
     * El conductor denuncia al pasajero de un viaje suyo.
     *
     * Es la contraparte de la denuncia del pasajero: mismo formulario, otra lista de
     * motivos (no pagó, dañó el vehículo, no se presentó).
     */
    public function reportPassenger(Request $request)
    {
        $driver = $this->driver($request);
        $data = $request->validate([
            'code'    => ['required', 'string'],
            'reason'  => ['required', 'string', 'max:60'],
            'details' => ['nullable', 'string', 'max:600'],
        ]);

        $ride = Ride::where('code', $data['code'])
            ->where('driver_id', $driver->id)
            ->whereNotNull('passenger_id')
            ->firstOrFail();

        Reports::submit($ride, 'driver', $driver->id, 'passenger', (int) $ride->passenger_id, $data['reason'], $data['details'] ?? null);

        return response()->json(['ok' => true]);
    }

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

        // Precio cerrado: se cobra EXACTAMENTE lo pactado al aceptar el viaje (viaje + aproximación).
        // No se recalcula con el tiempo real transcurrido aunque haya habido tráfico o demoras,
        // ni se vuelve a medir la aproximación: approach_fee quedó congelado al aceptar.
        $finalPrice = $ride->totalPrice();
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

    /**
     * El conductor sube o reemplaza la foto de su vehículo desde su propio celular.
     * Es la vía práctica: la central no tiene por qué juntar las fotos de cada auto a mano.
     */
    /**
     * El conductor envía su foto de perfil o la de su vehículo.
     * No se publica: queda pendiente hasta que la central la apruebe.
     */
    public function uploadPhoto(Request $request, string $type)
    {
        $driver = $this->driver($request);

        if (! in_array($type, \App\Models\DriverPhoto::TYPES, true)) {
            return response()->json(['message' => 'Tipo de foto no válido.'], 404);
        }

        $label = \App\Services\DriverPhotos::label($type);

        $request->validate(
            ['photo' => array_merge(['required'], array_slice(\App\Services\DriverPhotos::RULES, 1))],
            [
                'photo.required' => "Elige {$label}.",
                'photo.uploaded' => 'No se pudo subir la foto: pesa demasiado. Tómala de nuevo.',
                'photo.image'    => 'El archivo debe ser una imagen.',
                'photo.mimes'    => 'Usa una foto JPG, PNG o WEBP.',
                'photo.max'      => 'La foto es muy pesada (máx. 12 MB).',
            ]
        );

        try {
            \App\Services\DriverPhotos::submit($driver, $type, $request->file('photo'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'No se pudo procesar la foto. Intenta con otra.'], 422);
        }

        return response()->json([
            'ok'      => true,
            'photos'  => \App\Services\DriverPhotos::states($driver->fresh()),
            'message' => 'Foto enviada. La central la revisará antes de publicarla.',
        ]);
    }

    /** El conductor retira una foto: se va tanto la pendiente como la que estaba publicada. */
    public function deletePhoto(Request $request, string $type)
    {
        $driver = $this->driver($request);

        if (! in_array($type, \App\Models\DriverPhoto::TYPES, true)) {
            return response()->json(['message' => 'Tipo de foto no válido.'], 404);
        }

        $column = \App\Services\DriverPhotos::liveColumn($type);

        foreach ($driver->photos()->where('type', $type)->get() as $p) {
            \App\Services\ImageStore::delete($p->path);
            $p->delete();
        }
        \App\Services\ImageStore::delete($driver->{$column});
        $driver->update([$column => null]);

        return response()->json([
            'ok'      => true,
            'photos'  => \App\Services\DriverPhotos::states($driver->fresh()),
            'message' => 'Foto eliminada.',
        ]);
    }

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
            'vehicle_photo'  => \App\Services\VehiclePhoto::url($driver->vehicle_photo),
            'photos'         => \App\Services\DriverPhotos::states($driver),
            'photos_required'=> \App\Services\DriverPhotos::required(),
            'photo_block'    => \App\Services\DriverPhotos::blockMessage($driver),
            'commission_pct' => Fare::commissionPct(),
            'min_saldo'      => Fare::minSaldo(),
            'min_alert'      => (float) Setting::get('min_saldo_alert', 5.00),
            'can_receive' => $driver->canReceiveRides(),
            'currency'    => Setting::get('currency', 'S/'),
            'yape_number' => Setting::get('yape_number', ''),
            'yape_holder' => Setting::get('yape_holder', ''),
            'payment'     => $this->paymentMethods(),
            'recharge_note' => (string) Setting::get('recharge_note', ''),
            'tiers'       => array_values(array_filter(array_map('trim', explode(',', (string) Setting::get('saldo_tiers', '20,50,100'))))),
            'movements'   => $moves,
            'pending'     => $driver->recharges()->where('status', 'pendiente')->latest()->get()->map(fn ($r) => [
                'amount'  => (float) $r->amount,
                'method'  => $r->methodLabel(),
                'date'    => $r->created_at->format('d/m/Y H:i'),
                'receipt' => $r->receiptUrl(),
            ]),
        ]);
    }

    /**
     * Medios de pago habilitados para recargar saldo, con los datos que el conductor copia.
     * Solo se devuelven los que la central llenó en Configuración: si no hay número de Plin,
     * el conductor no ve la pestaña de Plin y no puede quedarse esperando a un dato que no existe.
     */
    private function paymentMethods(): array
    {
        $methods = [];

        if ($yape = trim((string) Setting::get('yape_number', ''))) {
            $methods[] = [
                'key' => 'yape', 'label' => 'Yape', 'icon' => '💜',
                'fields' => [
                    ['label' => 'Número de Yape', 'value' => $yape, 'copy' => true, 'big' => true],
                    ['label' => 'Titular', 'value' => trim((string) Setting::get('yape_holder', '')), 'copy' => false],
                ],
            ];
        }

        if ($plin = trim((string) Setting::get('plin_number', ''))) {
            $methods[] = [
                'key' => 'plin', 'label' => 'Plin', 'icon' => '💙',
                'fields' => [
                    ['label' => 'Número de Plin', 'value' => $plin, 'copy' => true, 'big' => true],
                    ['label' => 'Titular', 'value' => trim((string) Setting::get('plin_holder', '')), 'copy' => false],
                ],
            ];
        }

        $account = trim((string) Setting::get('bank_account', ''));
        $cci     = trim((string) Setting::get('bank_cci', ''));
        if ($account || $cci) {
            $methods[] = [
                'key' => 'transferencia', 'label' => 'Transferencia', 'icon' => '🏦',
                'fields' => array_values(array_filter([
                    ['label' => 'Banco', 'value' => trim((string) Setting::get('bank_name', '')), 'copy' => false],
                    $account ? ['label' => 'Número de cuenta', 'value' => $account, 'copy' => true, 'big' => true] : null,
                    $cci ? ['label' => 'CCI (interbancario)', 'value' => $cci, 'copy' => true] : null,
                    ['label' => 'Titular', 'value' => trim((string) Setting::get('bank_holder', '')), 'copy' => false],
                ], fn ($f) => $f && $f['value'] !== '')),
            ];
        }

        return $methods;
    }

    /**
     * El conductor envía su recarga a revisión (queda pendiente hasta que el admin la valide).
     *
     * Llega como multipart porque puede traer la foto del voucher. La recarga solo se registra
     * si el conductor ya pagó: o adjunta el comprobante, o declara el pago con su número de
     * operación. Así la central no recibe pedidos "en blanco" que tendría que perseguir.
     */
    public function recharge(Request $request)
    {
        $driver = $this->driver($request);

        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'min:1', 'max:1000'],
            'method'    => ['required', 'in:yape,plin,transferencia'],
            'reference' => ['nullable', 'string', 'max:60'],
            'confirmed' => ['nullable'],
            'receipt'   => \App\Services\Receipt::RULES,
        ], \App\Services\Receipt::messages());

        $hasReceipt = $request->hasFile('receipt');

        if (! $hasReceipt && ! $request->boolean('confirmed')) {
            return response()->json([
                'message' => 'Adjunta la foto de tu comprobante o confirma que ya hiciste el pago.',
            ], 422);
        }

        // doble toque en «Enviar»: no duplicar la misma recarga en el panel de la central
        $duplicate = $driver->recharges()
            ->where('status', 'pendiente')
            ->where('amount', round($data['amount'], 2))
            ->where('created_at', '>=', now()->subMinutes(10))
            ->first();

        if ($duplicate) {
            return response()->json([
                'ok'      => true,
                'message' => 'Ya tienes esta recarga en revisión. La central la validará pronto.',
                'code'    => $duplicate->id,
            ]);
        }

        $receiptPath = null;
        if ($hasReceipt) {
            try {
                $receiptPath = \App\Services\Receipt::store($request->file('receipt'), $driver);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'No se pudo procesar el comprobante. Toma la foto de nuevo.'], 422);
            }
        }

        $recharge = Recharge::create([
            'driver_id'    => $driver->id,
            'amount'       => round($data['amount'], 2),
            'method'       => $data['method'],
            'reference'    => $data['reference'] ?: null,
            'receipt_path' => $receiptPath,
            'status'       => 'pendiente',
        ]);

        return response()->json([
            'ok'      => true,
            'message' => $receiptPath
                ? 'Comprobante enviado. La central validará tu recarga y se acreditará tu saldo.'
                : 'Recarga enviada a revisión. La central la validará y se acreditará tu saldo.',
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
            'price'  => (float) ($r->final_price ?? $r->totalPrice()),
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
        $earnings = $todayRides->sum(fn (Ride $r) => (float) ($r->final_price ?? $r->totalPrice()) - (float) ($r->commission ?? 0));

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
            'approach_m'   => $ride->approach_m !== null ? (int) $ride->approach_m : null,
            'approach_fee' => (float) $ride->approach_fee,
            'counter_offer'=> (float) $ride->counter_offer,
            'total_price'  => $ride->totalPrice(),
            'final_price'  => $ride->final_price !== null ? (float) $ride->final_price : null,
            'commission'   => $ride->commission !== null ? (float) $ride->commission : Fare::commission($ride->totalPrice()),
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
