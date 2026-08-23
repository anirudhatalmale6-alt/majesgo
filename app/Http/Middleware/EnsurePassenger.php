<?php

namespace App\Http\Middleware;

use App\Models\Passenger;
use App\Services\AppSource;
use Closure;
use Illuminate\Http\Request;

class EnsurePassenger
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->session()->get('passenger_id');
        $passenger = $id ? Passenger::find($id) : null;

        if (! $passenger) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        /*
         * El estado de la cuenta se comprueba en CADA petición, no solo al iniciar sesión.
         * Antes solo lo miraba el login: si el pasajero ya tenía la sesión abierta en su
         * teléfono, la central lo bloqueaba y él seguía pidiendo taxis igual, porque nunca
         * volvía a pasar por el login. El bloqueo no servía de nada justo con quien hay que
         * usarlo. Se cierra la sesión para que la app lo mande a la pantalla de acceso.
         */
        if ($passenger->isBlocked()) {
            $request->session()->forget('passenger_id');

            return response()->json([
                'message' => $passenger->blockedMessage(),
                'blocked' => true,
            ], 403);
        }

        // ¿está usando la app de Play o el navegador? (con freno, no escribe en cada llamada)
        AppSource::touch($passenger, $request);

        // disponible para los controladores vía $request->passenger()
        $request->setUserResolver(fn () => $passenger);
        $request->attributes->set('passenger', $passenger);

        return $next($request);
    }
}
