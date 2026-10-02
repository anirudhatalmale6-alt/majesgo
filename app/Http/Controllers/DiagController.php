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

        /*
         * ⚠ ARCHIVO PROPIO Y NIVEL PROPIO, NO el log de la app.
         *
         * Primer intento: Log::info() al canal de siempre. La app corre con LOG_LEVEL=error,
         * así que los avisos se tiraban a la basura sin decir nada. El endpoint contestaba
         * 200, el celular creía haber informado y el archivo seguía intacto desde el 26 de
         * septiembre. Si no lo hubiera comprobado, habría leído "no hay eventos" y le habría
         * dicho a Joel que su teléfono nunca pidió apagar el servicio — una conclusión falsa
         * sacada del silencio de mi propia sonda.
         *
         * Con Log::build() la sonda trae su propio archivo y su propio nivel: no depende de
         * la configuración de la app ni ensucia el log de errores de verdad.
         */
        Log::build([
            'driver' => 'single',
            'path'   => storage_path('logs/rastreo.log'),
            'level'  => 'debug',
        ])->debug('conductor '.$driver->id.': '.$d['evento']);

        return response()->json(['ok' => true]);
    }
}
