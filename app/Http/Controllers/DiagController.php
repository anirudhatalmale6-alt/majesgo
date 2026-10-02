<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sonda TEMPORAL para el aviso fijo que no se va al terminar la carrera.
 *
 * Por qué existe: el servicio de ubicación es NATIVO y corre en el celular de Joel. Yo no
 * tengo ese celular ni veo su consola, y ya me equivoqué dos veces adivinando desde acá. En
 * vez de seguir suponiendo, la app cuenta lo que hizo — arrancó, paró, el plugin contestó o
 * falló — y queda escrito en el log del servidor, que sí puedo leer.
 *
 * Sólo nombres de evento e identificadores del vigilante. Nada del pasajero, nada del viaje,
 * ninguna posición.
 *
 * ⚠ QUITAR cuando el aviso quede resuelto. Una sonda que se olvida termina siendo ruido en
 * el log y una puerta abierta que nadie recuerda haber dejado.
 */
class DiagController extends Controller
{
    public function rastreo(Request $request)
    {
        $d = $request->validate([
            'token'  => ['required', 'string', 'size:48'],
            'evento' => ['required', 'string', 'max:200'],
        ]);

        $driver = Driver::where('location_token', $d['token'])->first();
        if (! $driver) {
            return response()->json(['message' => 'Token no válido.'], 401);
        }

        Log::channel('single')->info('[rastreo] conductor '.$driver->id.': '.$d['evento']);

        return response()->json(['ok' => true]);
    }
}
