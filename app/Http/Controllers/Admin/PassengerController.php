<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Passenger;
use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PassengerController extends Controller
{
    public function index(Request $request)
    {
        $q = Passenger::query();

        if ($search = $request->get('q')) {
            $digits = preg_replace('/\D/', '', $search);
            $q->where(function ($w) use ($search, $digits) {
                $w->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
                if ($digits !== '') {
                    $w->orWhere('phone', 'like', "%{$digits}%");
                }
            });
        }

        if ($status = $request->get('estado')) {
            $q->where('account_status', $status);
        }

        // Los contadores se sacan de la tabla de viajes, no de las columnas acumuladas:
        // esas se incrementan en varios sitios (incluido el simulador de demo) y pueden
        // quedar desfasadas. Los viajes de prueba no cuentan como viajes reales.
        // reorder(): la relación rides() ya trae un latest() y no tiene sentido ordenar
        // dentro de un COUNT.
        $q->withCount([
            'rides as viajes_ok' => function ($r) {
                $r->reorder()->where('status', 'completado')->where('is_demo', false);
            },
            'rides as viajes_cancel' => function ($r) {
                $r->reorder()->where('status', 'cancelado')->where('is_demo', false);
            },
        ]);

        $passengers = $q->latest()->paginate(15)->withQueryString();

        $resumen = [
            'total'       => Passenger::count(),
            'activos'     => Passenger::where('account_status', 'activo')->count(),
            'bloqueados'  => Passenger::where('account_status', '!=', 'activo')->count(),
            'nuevos_7d'   => Passenger::where('created_at', '>=', now()->subDays(7))->count(),
        ];

        return view('admin.passengers.index', compact('passengers', 'resumen'));
    }

    public function show(Request $request, Passenger $passenger)
    {
        $rides = $passenger->rides()
            ->with('driver')
            ->paginate(15)
            ->withQueryString();

        $stats = [
            'completados' => $this->countRides($passenger, ['completado']),
            'cancelados'  => $this->countRides($passenger, ['cancelado']),
            'sin_conductor' => $this->countRides($passenger, ['sin_conductor']),
            'gastado'     => (float) Ride::where('passenger_id', $passenger->id)
                                ->where('status', 'completado')->where('is_demo', false)
                                ->sum('final_price'),
            // Si nadie lo calificó todavía, la columna sigue en su 5.00 por defecto:
            // mostrarlo como "5 estrellas" seria inventar una reputación que no existe.
            'calificaciones' => Ride::where('passenger_id', $passenger->id)
                                ->whereNotNull('rating_to_passenger')->count(),
            'demo'        => Ride::where('passenger_id', $passenger->id)->where('is_demo', true)->count(),
        ];

        $activo = $passenger->activeRide();

        return view('admin.passengers.show', compact('passenger', 'rides', 'stats', 'activo'));
    }

    /** Activar / suspender / bloquear la cuenta del pasajero. */
    public function setAccountStatus(Request $request, Passenger $passenger)
    {
        $data = $request->validate([
            'account_status' => ['required', 'in:activo,suspendido,bloqueado'],
        ]);

        $passenger->update($data);

        // Un pasajero bloqueado con un viaje en curso dejaría al conductor colgado:
        // el bloqueo corta el acceso, no el viaje que ya está pagándose.
        $aviso = '';
        if ($data['account_status'] !== 'activo' && $passenger->activeRide()) {
            $aviso = ' Ojo: tiene un viaje en curso, ese viaje sigue hasta que termine o se cancele.';
        }

        return back()->with('ok', "Cuenta de {$passenger->name} ahora: {$passenger->accountLabel()}.{$aviso}");
    }

    /** Clave nueva para soporte: el pasajero no tiene forma de recuperarla solo. */
    public function resetPassword(Request $request, Passenger $passenger)
    {
        $data = $request->validate([
            'password' => ['required', 'min:4'],
        ]);

        $passenger->update(['password' => Hash::make($data['password'])]);

        return back()->with('ok', "Clave de {$passenger->name} actualizada. Pásasela por WhatsApp y pídele que la cambie… o mejor, que la anote.");
    }

    private function countRides(Passenger $p, array $states): int
    {
        return Ride::where('passenger_id', $p->id)
            ->whereIn('status', $states)
            ->where('is_demo', false)
            ->count();
    }
}
