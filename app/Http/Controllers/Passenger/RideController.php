<?php

namespace App\Http\Controllers\Passenger;

use App\Http\Controllers\Controller;
use App\Models\Passenger;
use App\Models\Ride;
use App\Models\Setting;
use App\Services\DemoSim;
use App\Services\Dispatch;
use App\Services\Fare;
use App\Services\Routing;
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
            'currency'   => Setting::get('currency', 'S/'),
        ]);
    }

    /** Crea la solicitud de viaje. */
    public function store(Request $request)
    {
        $passenger = $this->passenger($request);

        if ($passenger->activeRide()) {
            return response()->json(['message' => 'Ya tienes un viaje en curso.'], 422);
        }

        $d = $request->validate([
            'origin_lat' => ['required', 'numeric'],
            'origin_lng' => ['required', 'numeric'],
            'origin_address' => ['nullable', 'string', 'max:180'],
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

        return response()->json(['ok' => true, 'ride' => ['code' => $ride->code]]);
    }

    /** Estado del viaje activo + posición del conductor en vivo (para hacer polling). */
    public function current(Request $request)
    {
        $passenger = $this->passenger($request);
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

        // Búsqueda de conductor
        if ($ride->status === 'solicitando') {
            // ¿Hay conductores REALES conectados y elegibles para este viaje?
            $realOnline = false;
            foreach (Dispatch::eligibleDrivers((float) $ride->origin_lat, (float) $ride->origin_lng) as $e) {
                if (! $e['driver']->is_demo) { $realOnline = true; break; }
            }

            // Si hay conductores reales en línea, esperamos a que uno acepte desde su app.
            if ($realOnline) {
                return response()->json(['ride' => $this->payload($ride, null)]);
            }

            // Si no hay conductores reales conectados, usamos el conductor de prueba
            // para poder probar el recorrido sin necesitar dos personas.
            if ((string) Setting::get('demo_enabled', '1') === '1') {
                $delay = (int) Setting::get('search_delay_s', 3);
                $waited = now()->getTimestamp() - $ride->requested_at->getTimestamp();

                if ($waited >= $delay) {
                    $this->assignDemoDriver($ride);
                    $driver = $ride->driver;
                    return response()->json(['ride' => $this->payload($ride, [
                        'lat' => (float) $driver->lat, 'lng' => (float) $driver->lng,
                    ])]);
                }
            }

            return response()->json(['ride' => $this->payload($ride, null)]);
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

    private function assignDemoDriver(Ride $ride): void
    {
        $driver = Dispatch::demoDriver((float) $ride->origin_lat, (float) $ride->origin_lng);
        $toPickup = Routing::route((float) $driver->lat, (float) $driver->lng, (float) $ride->origin_lat, (float) $ride->origin_lng);

        $ride->forceFill([
            'driver_id'       => $driver->id,
            'status'          => 'aceptado',
            'accepted_at'     => now(),
            'route_to_pickup' => $toPickup['geometry'],
            'is_demo'         => true,
        ])->save();

        $driver->update(['status' => 'ocupado']);
    }

    public function cancel(Request $request)
    {
        $passenger = $this->passenger($request);
        $ride = $passenger->activeRide();

        if (! $ride) {
            return response()->json(['message' => 'No tienes un viaje activo.'], 422);
        }

        $ride->forceFill([
            'status'        => 'cancelado',
            'cancelled_by'  => 'pasajero',
            'cancel_reason' => $request->input('reason'),
            'cancelled_at'  => now(),
        ])->save();

        $passenger->increment('cancel_count');

        if ($ride->driver && $ride->driver->is_demo) {
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

    public function history(Request $request)
    {
        $passenger = $this->passenger($request);
        $rides = $passenger->rides()->take(30)->get()->map(fn (Ride $r) => [
            'code'       => $r->code,
            'status'     => $r->status,
            'status_label' => $r->statusLabel(),
            'origin'     => $r->origin_address,
            'dest'       => $r->dest_address,
            'price'      => (float) ($r->final_price ?? $r->offered_price),
            'method'     => $r->payment_method,
            'date'       => $r->created_at->format('d/m/Y H:i'),
        ]);

        return response()->json(['rides' => $rides, 'currency' => Setting::get('currency', 'S/')]);
    }

    private function payload(Ride $ride, ?array $pos): array
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
            'final_price'  => $ride->final_price !== null ? (float) $ride->final_price : null,
            'payment_method' => $ride->payment_method,
            'distance_m'   => $ride->distance_m,
            'duration_s'   => $ride->duration_s,
            'origin'       => ['lat' => (float) $ride->origin_lat, 'lng' => (float) $ride->origin_lng, 'address' => $ride->origin_address],
            'dest'         => ['lat' => (float) $ride->dest_lat, 'lng' => (float) $ride->dest_lng, 'address' => $ride->dest_address],
            'route_to_pickup' => $ride->route_to_pickup,
            'route_trip'   => $ride->route_trip,
            'driver_pos'   => $pos,
            'driver'       => $driver ? [
                'name'    => $driver->full_name,
                'vehicle' => trim(($driver->vehicle_make . ' ' . $driver->vehicle_model)),
                'plate'   => $driver->vehicle_plate,
                'color'   => $driver->vehicle_color,
                'rating'  => (float) $driver->rating,
                'trips'   => $driver->total_trips,
                'initial' => mb_strtoupper(mb_substr($driver->full_name, 0, 1)),
            ] : null,
        ];
    }
}
