<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Ride;
use App\Models\Setting;
use App\Services\Fare;
use App\Services\WebPushSender;
use Illuminate\Support\Facades\Cache;

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
    /**
     * Radio de búsqueda que se EXPANDE solo con la espera: arranca en la base y crece
     * por pasos hasta un máximo, para encontrar conductores algo más lejos si no hay cerca.
     */
    public static function radiusForWait(int $waitedS): float
    {
        $base  = (float) Setting::get('dispatch_radius_km', 5.0);
        $max   = (float) Setting::get('dispatch_radius_max_km', 10.0);
        $step  = (float) Setting::get('dispatch_radius_step_km', 1.0);
        $every = max(1, (int) Setting::get('dispatch_radius_step_s', 12));
        $r = $base + floor(max(0, $waitedS) / $every) * $step;

        return min($r, max($base, $max));
    }

    /**
     * Cuánto tiempo sigue contando como CONECTADO un conductor desde su última señal de vida.
     *
     * ⚠ NO confundir con 'driver_stale_s', que es otra cosa: ese decide si su POSICIÓN es lo
     * bastante fresca para dibujar su auto en el mapa del pasajero (ahí una posición de hace
     * horas sería mentira). La presencia es más larga a propósito.
     *
     * Por qué: el latido lo manda la app desde el navegador. Cuando el conductor minimiza la
     * app, abre WhatsApp o bloquea la pantalla, Android congela ese temporizador y el latido
     * se detiene. Con una ventana corta el conductor desaparecía del despacho a los pocos
     * minutos — y, peor, dejaba de recibir el aviso push que era justo lo que iba a
     * despertarlo: estaba dormido, así que no lo despertábamos. Círculo cerrado.
     * (Reportado por el cliente el 2026-08-24 tras las pruebas con conductores reales.)
     *
     * El aviso push es barato y no asigna nada: si un conductor ya no está, simplemente no
     * responde y la carrera se la lleva otro. Ya no hay riesgo de "bloquear" el despacho como
     * en el modelo viejo de ofrecer de uno en uno.
     */
    public static function presenceWindowS(): int
    {
        return max(60, (int) Setting::get('driver_presence_s', 28800)); // 8 h
    }

    public static function eligibleDrivers(float $lat, float $lng, ?float $radiusKm = null, array $excludeIds = []): array
    {
        $radiusKm ??= (float) Setting::get('dispatch_radius_km', 5.0);
        // saldo mínimo = comisión de la carrera más barata posible (tarifa mínima)
        $minSaldo = Fare::minSaldo();
        $staleS = self::presenceWindowS();

        $drivers = Driver::query()
            ->where('status', 'disponible')
            ->where('account_status', 'activo')
            // La cuenta del revisor de Google NUNCA entra al despacho real: si entrara,
            // podría quedarse con el viaje de un pasajero de Majes y dejarlo esperando
            // un auto que no existe. Recibe su propia solicitud simulada (ver ReviewerSim).
            ->where('is_reviewer', false)
            ->where('saldo', '>=', $minSaldo)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            // Ventana de PRESENCIA (larga, ver presenceWindowS): el conductor sigue disponible
            // aunque tenga la app en segundo plano. Los que llevan horas sin dar señales los
            // desconecta disconnectAbandoned(), no este filtro.
            ->where(function ($q) use ($staleS) {
                $q->where('last_active_at', '>=', now()->subSeconds($staleS))
                  ->orWhere('is_demo', true);
            })
            // fotos aprobadas por la central: si el requisito está activo, un conductor sin
            // rostro o sin vehículo aprobados no entra al despacho aunque esté conectado.
            // El conductor demo queda exento: es una simulación interna, no lo ve un pasajero real.
            ->when(DriverPhotos::required(), fn ($q) => $q->where(function ($w) {
                $w->where('is_demo', true)
                  ->orWhere(fn ($x) => $x->whereNotNull('photo_path')->whereNotNull('vehicle_photo'));
            }))
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

    /** Etiqueta del aviso de este viaje: la comparten el aviso y su cancelación. */
    public static function rideTag(Ride $ride): string
    {
        return 'viaje-'.$ride->code;
    }

    /** A quién le sonó este viaje (para poder apagárselo después). */
    private static function pushedKey(Ride $ride): string
    {
        return 'push_viaje_'.$ride->id;
    }

    /** Un conductor descartó este viaje: no volver a sonarle en los recordatorios. */
    public static function markDismissed(Ride $ride, int $driverId): void
    {
        Cache::put('descarta_'.$ride->id.'_'.$driverId, 1, 600);
    }

    /**
     * Notifica por push a los conductores elegibles cercanos que hay un viaje esperando.
     *
     * Cada conductor recibe SU aviso: la tarifa incluye su acercamiento y la distancia es la
     * suya, así puede decidir desde la pantalla bloqueada sin abrir la app.
     */
    public static function notifyNearbyDrivers(Ride $ride): void
    {
        if ($ride->status !== 'solicitando') {
            return;
        }
        // El viaje del revisor de las tiendas no le suena a nadie: es una simulación para que
        // pueda completar el flujo, no una carrera que alguien deba ir a recoger.
        if ($ride->esDeRevision()) {
            return;
        }
        $waited = max(0, now()->timestamp - $ride->requested_at->timestamp);
        $radius = self::radiusForWait($waited);
        $restan = self::searchSecondsLeft($ride);
        if ($restan < 5) {
            return; // ya casi no existe: un aviso ahora solo sirve para frustrar
        }

        $moneda = Setting::get('currency', 'S/');
        $origen = $ride->origin_address ?: 'Punto de recojo';
        $destino = $ride->dest_address ?: 'Destino';
        $tag = self::rideTag($ride);
        $avisados = [];

        foreach (self::eligibleDrivers((float) $ride->origin_lat, (float) $ride->origin_lng, $radius, (array) $ride->excluded_driver_ids) as $e) {
            $d = $e['driver'];
            if ($d->is_demo || Cache::get('descarta_'.$ride->id.'_'.$d->id)) {
                continue;
            }

            // mismo cálculo que ve en la tarjeta al abrir la app: el aviso no puede prometer
            // un monto y la app mostrar otro
            $acerca = Fare::approach(Fare::approachDistance((float) $d->lat, (float) $d->lng, (float) $ride->origin_lat, (float) $ride->origin_lng));
            $total  = Fare::total((float) $ride->offered_price, $acerca);
            $km     = round($e['distance_m'] / 1000, 1);

            $avisados[] = $d->id;
            WebPushSender::toOwner('driver', $d->id, [
                'title' => '🚕 '.$moneda.' '.number_format($total, 2).' · a '.$km.' km de ti',
                'body'  => $origen.' → '.$destino,
                'url'   => '/conductor',
                'tag'   => $tag,
                'ttl'   => min(60, $restan),
            ]);
        }

        // Se ACUMULA, no se reemplaza: hay que poder apagarle el aviso a cualquiera al que le
        // haya sonado alguna vez por este viaje, no solo a los del último recordatorio (el que
        // rechazó o el que se salió del radio también tienen la notificación en la barra).
        $previos = (array) Cache::get(self::pushedKey($ride), []);
        Cache::put(self::pushedKey($ride), array_values(array_unique(array_merge($previos, $avisados))), 900);
        \Log::info('notifyNearbyDrivers ride='.$ride->code.' avisados='.count($avisados));
    }

    /**
     * Vuelve a sonarle a los que aún no respondieron, mientras el pasajero sigue esperando.
     *
     * Lo dispara el sondeo del pasajero, así no hace falta un proceso en segundo plano. Con
     * tope y con freno: un aviso que insiste para siempre se convierte en un aviso que el
     * conductor silencia, y volvemos al problema de que no se entera de nada.
     */
    public static function remindWhileSearching(Ride $ride): void
    {
        if ($ride->status !== 'solicitando') {
            return;
        }
        $freno = 'recuerda_'.$ride->id;
        $veces = 'recuerda_n_'.$ride->id;
        if (Cache::get($freno) || (int) Cache::get($veces, 0) >= 3) {
            return;
        }
        Cache::put($freno, 1, 12);
        Cache::put($veces, (int) Cache::get($veces, 0) + 1, 600);

        self::notifyNearbyDrivers($ride);
    }

    /**
     * Apaga el aviso que quedó sonando en los celulares de los que NO se quedaron con la
     * carrera. Va con el mismo tag, así reemplaza al aviso ruidoso, y por un canal sin sonido
     * para no volver a molestar. Sin esto, el conductor abre la app buscando una carrera que
     * ya no existe.
     */
    public static function notifyRideTaken(Ride $ride, ?int $winnerId = null, string $motivo = 'Ya la tomó otro conductor'): void
    {
        $ids = array_values(array_diff((array) Cache::pull(self::pushedKey($ride), []), array_filter([$winnerId])));
        if (! $ids) {
            return;
        }

        WebPushSender::toOwners('driver', $ids, [
            'title'  => 'Carrera ya no disponible',
            'body'   => $motivo,
            'url'    => '/conductor',
            'tag'    => self::rideTag($ride),
            'silent' => true,
            'ttl'    => 120,
        ]);
    }

    /**
     * Desconecta a los conductores que llevan más de la ventana de presencia sin dar señales.
     *
     * Es la contraparte de la ventana larga: sin esto, alguien que cerró la app y nunca pulsó
     * «desconectarme» se quedaría «disponible» para siempre. Se ejecuta desde el sondeo (igual
     * que expireStaleSearches) para no depender de un cron.
     *
     * Nunca toca a un conductor con viaje en curso: ahí el que manda es el viaje, no el latido.
     *
     * @return int cuántos se desconectaron
     */
    public static function disconnectAbandoned(): int
    {
        $limite = now()->subSeconds(self::presenceWindowS());

        $ids = Driver::query()
            ->where('status', 'disponible')
            ->where('is_demo', false)
            ->where(fn ($q) => $q->whereNull('last_active_at')->orWhere('last_active_at', '<', $limite))
            ->whereDoesntHave('rides', fn ($q) => $q->whereIn('status', Ride::ACTIVE_STATES))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return Driver::whereIn('id', $ids)->update(['status' => 'desconectado']);
    }

    /* ---------- Límite de la búsqueda ---------- */

    /**
     * Cuánto tiempo se busca conductor antes de darse por vencido.
     *
     * ⚠ Este MISMO valor decide hasta cuándo el viaje le aparece a los conductores
     * (Driver\RideController::pending). Si se separan, el pasajero puede quedarse mirando
     * "Buscando tu taxi…" un viaje que ningún conductor ve ya: fue exactamente el problema
     * reportado el 2026-08-13 (10 minutos buscando algo imposible).
     */
    public static function searchTimeoutS(): int
    {
        return max(30, (int) Setting::get('search_timeout_s', 180));
    }

    /** Segundos que le quedan a la búsqueda de este viaje (0 = se acabó). */
    public static function searchSecondsLeft(Ride $ride): int
    {
        if (! $ride->requested_at) {
            return 0;
        }
        $waited = now()->getTimestamp() - $ride->requested_at->getTimestamp();

        return max(0, self::searchTimeoutS() - $waited);
    }

    /**
     * Cierra las búsquedas que ya pasaron del límite: el viaje pasa a 'sin_conductor' y el
     * pasajero deja de esperar. Se llama desde el sondeo del pasajero y también desde el de
     * los conductores, para que el viaje se cierre aunque el pasajero haya cerrado la app.
     *
     * @return int cuántos viajes se cerraron
     */
    public static function expireStaleSearches(): int
    {
        $stale = Ride::where('status', 'solicitando')
            ->where('requested_at', '<', now()->subSeconds(self::searchTimeoutS()))
            ->get();

        foreach ($stale as $ride) {
            $ride->forceFill(['status' => 'sin_conductor', 'cancelled_at' => now()])->save();

            // apagar el aviso en los celulares donde quedó sonando
            defer(fn () => self::notifyRideTaken($ride, null, 'La búsqueda terminó.'));

            defer(fn () => WebPushSender::toOwner('passenger', (int) $ride->passenger_id, [
                'title' => 'No encontramos conductor',
                'body'  => 'Ningún conductor tomó tu viaje. Toca para intentar de nuevo.',
                'url'   => '/app',
                'tag'   => 'ride-nodriver',
            ]));
        }

        return $stale->count();
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
            // el costo de aproximación era el del conductor que se cayó: se borra para que
            // el siguiente lo fije con SU distancia (si no, el pasajero pagaría el recojo de otro)
            'approach_m'          => null,
            'approach_fee'        => 0,
            // lo mismo con el ajuste: era la contraoferta de ESE conductor, no la del siguiente
            'counter_offer'       => 0,
            'is_demo'             => false,
            // Re-emisión "fresca": renueva la ventana de búsqueda y hace que las omisiones
            // (descartes por tiempo) previas de OTROS conductores ya no cuenten → vuelve a sonar.
            'requested_at'        => now(),
        ])->save();

        if ($driverId && ($d = Driver::find($driverId)) && ! $d->activeRide()) {
            $d->update(['status' => 'disponible']);
        }

        // Viaje re-emitido: volver a avisar por push a los conductores cercanos (menos el excluido).
        defer(fn () => self::notifyNearbyDrivers($ride->fresh()));
    }

    /**
     * Conductor de simulación para probar el flujo del pasajero antes de la app del conductor (Hito 3).
     * Lo posiciona a una distancia corta del recojo para que se vea acercándose.
     */
    public static function demoDriver(float $pickupLat, float $pickupLng): Driver
    {
        // withTrashed: Driver usa borrado lógico y el conductor de prueba puede haber quedado
        // dado de baja en una limpieza anterior (pasó el 2026-08-12, antes de salir en vivo).
        // Sin esto firstOrNew no lo ve, intenta insertarlo otra vez y choca contra el índice
        // único de `code` → 500 en /rides/current, que es justo lo que pollea la app.
        $driver = Driver::withTrashed()->firstOrNew(['code' => 'MG-DEMO']);

        if ($driver->exists && $driver->trashed()) {
            $driver->restore();
        }

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
