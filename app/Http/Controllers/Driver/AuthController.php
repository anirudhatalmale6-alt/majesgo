<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function me(Request $request)
    {
        $id = $request->session()->get('driver_id');
        $d = $id ? Driver::find($id) : null;

        if (! $d) {
            return response()->json(['authenticated' => false, 'csrf' => csrf_token()]);
        }

        return response()->json([
            'authenticated' => true,
            'driver' => $this->publicData($d),
            'csrf' => csrf_token(),
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // aceptar el número con o sin código de país / espacios / guiones (compara por los últimos 9 dígitos)
        $digits = preg_replace('/\D/', '', $data['phone']);
        $candidates = array_values(array_unique(array_filter([
            $data['phone'],
            $digits,
            strlen($digits) > 9 ? substr($digits, -9) : $digits,
        ])));
        $d = Driver::whereIn('phone', $candidates)->first();

        if (! $d || ! Hash::check($data['password'], $d->password)) {
            throw ValidationException::withMessages([
                'phone' => 'Número o clave incorrectos.',
            ]);
        }

        if ($d->account_status === 'bloqueado') {
            throw ValidationException::withMessages([
                'phone' => 'Tu cuenta está bloqueada. Comunícate con la central.',
            ]);
        }

        // Cada sesión empieza desconectado (salvo que haya un viaje en curso):
        // el conductor decide cuándo conectarse, y al hacerlo enviamos su ubicación.
        $update = ['last_active_at' => now()];
        if ($d->status === 'disponible' && ! $d->activeRide()) {
            $update['status'] = 'desconectado';
        }
        $d->update($update);

        $request->session()->put('driver_id', $d->id);
        $request->session()->regenerate();

        return response()->json(['ok' => true, 'driver' => $this->publicData($d->fresh()), 'csrf' => csrf_token()]);
    }

    public function logout(Request $request)
    {
        // al salir, dejar de recibir viajes
        if ($id = $request->session()->get('driver_id')) {
            if ($d = Driver::find($id)) {
                if ($d->status === 'disponible') {
                    $d->update(['status' => 'desconectado']);
                }
            }
        }
        $request->session()->forget('driver_id');
        $request->session()->regenerate();

        return response()->json(['ok' => true]);
    }

    private function publicData(Driver $d): array
    {
        return [
            'id'      => $d->id,
            'code'    => $d->code,
            'name'    => $d->full_name,
            'phone'   => $d->phone,
            'vehicle' => trim(($d->vehicle_make . ' ' . $d->vehicle_model)),
            'plate'   => $d->vehicle_plate,
            'color'   => $d->vehicle_color,
            'rating'  => (float) $d->rating,
            'trips'   => $d->total_trips,
            'saldo'   => (float) $d->saldo,
            'status'  => $d->status,
            'account_status' => $d->account_status,
            'commission_pct' => \App\Services\Fare::commissionPct(),
            'min_saldo'      => \App\Services\Fare::minSaldo(),
            'can_receive'    => $d->canReceiveRides(),
        ];
    }
}
