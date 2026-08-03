<?php

namespace App\Http\Controllers\Passenger;

use App\Http\Controllers\Controller;
use App\Models\Passenger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function me(Request $request)
    {
        $id = $request->session()->get('passenger_id');
        $p = $id ? Passenger::find($id) : null;

        if (! $p) {
            return response()->json(['authenticated' => false, 'csrf' => csrf_token()]);
        }

        return response()->json([
            'authenticated' => true,
            'passenger' => $this->publicData($p),
            'csrf' => csrf_token(),
        ]);
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'min:2', 'max:60'],
            'phone' => ['required', 'string', 'regex:/^9\d{8}$/'],
            'password' => ['required', 'string', 'min:4', 'max:20'],
        ], [
            'phone.regex' => 'Ingresa un celular válido de 9 dígitos (empieza con 9).',
            'password.min' => 'Tu clave debe tener al menos 4 caracteres.',
        ]);

        if (Passenger::where('phone', $data['phone'])->exists()) {
            throw ValidationException::withMessages([
                'phone' => 'Ese número ya está registrado. Inicia sesión.',
            ]);
        }

        $p = Passenger::create([
            'name'     => trim($data['name']),
            'phone'    => $data['phone'],
            'password' => Hash::make($data['password']),
            'last_active_at' => now(),
        ]);

        $request->session()->put('passenger_id', $p->id);
        $request->session()->regenerate();
        $p->refresh();

        return response()->json(['ok' => true, 'passenger' => $this->publicData($p), 'csrf' => csrf_token()]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // aceptar el número con o sin código de país / espacios (compara por los últimos 9 dígitos)
        $digits = preg_replace('/\D/', '', $data['phone']);
        $candidates = array_values(array_unique(array_filter([
            $data['phone'],
            $digits,
            strlen($digits) > 9 ? substr($digits, -9) : $digits,
        ])));
        $p = Passenger::whereIn('phone', $candidates)->first();

        if (! $p || ! Hash::check($data['password'], $p->password)) {
            throw ValidationException::withMessages([
                'phone' => 'Número o clave incorrectos.',
            ]);
        }

        if ($p->account_status !== 'activo') {
            throw ValidationException::withMessages([
                'phone' => 'Tu cuenta está suspendida. Contacta a soporte.',
            ]);
        }

        $p->update(['last_active_at' => now()]);
        $request->session()->put('passenger_id', $p->id);
        $request->session()->regenerate();

        return response()->json(['ok' => true, 'passenger' => $this->publicData($p), 'csrf' => csrf_token()]);
    }

    public function logout(Request $request)
    {
        $request->session()->forget('passenger_id');
        $request->session()->regenerate();
        return response()->json(['ok' => true]);
    }

    private function publicData(Passenger $p): array
    {
        return [
            'id'     => $p->id,
            'name'   => $p->name,
            'phone'  => $p->phone,
            'rating' => (float) $p->rating,
            'total_trips' => $p->total_trips,
        ];
    }
}
