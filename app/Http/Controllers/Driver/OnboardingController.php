<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\Driver;
use App\Models\Setting;
use App\Services\DriverDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Alta del conductor desde la app y envío de sus documentos.
 *
 * Antes la cuenta la creaba solo la central. Ahora cualquiera puede postularse, pero
 * NACE SIN PODER TRABAJAR: queda en 'registrado' y el candado de canReceiveRides() lo
 * mantiene fuera del despacho hasta que la central apruebe sus papeles.
 */
class OnboardingController extends Controller
{
    /** ¿Está abierto el registro desde la app? La central lo puede cerrar sin tocar código. */
    private function abierto(): bool
    {
        return (string) Setting::get('driver_signup_open', '0') === '1';
    }

    public function register(Request $request)
    {
        if (! $this->abierto()) {
            return response()->json([
                'message' => 'Por ahora las cuentas de conductor las crea la central. Comunícate con MajesGo.',
            ], 403);
        }

        $d = $request->validate([
            'full_name'     => ['required', 'string', 'min:5', 'max:120'],
            'phone'         => ['required', 'string', 'regex:/^\d{9}$/'],
            'dni'           => ['required', 'string', 'regex:/^[0-9A-Za-z]{8,12}$/'],
            'password'      => ['required', 'string', 'min:6', 'max:72'],
            'vehicle_make'  => ['required', 'string', 'max:40'],
            'vehicle_model' => ['required', 'string', 'max:40'],
            'vehicle_plate' => ['required', 'string', 'max:12'],
            'vehicle_color' => ['required', 'string', 'max:24'],
        ], [], [
            'full_name' => 'nombre completo', 'phone' => 'celular', 'dni' => 'DNI',
            'vehicle_plate' => 'placa',
        ]);

        $placa = strtoupper(preg_replace('/\s+/', '', $d['vehicle_plate']));
        $dni   = strtoupper(trim($d['dni']));

        // Un celular, un DNI y una placa identifican a una sola cuenta. Se comprueba contra
        // los borrados lógicos también: si no, alguien dado de baja vuelve a entrar con los
        // mismos datos y quedan dos cuentas compitiendo por la misma placa.
        foreach ([
            ['phone', $d['phone'], 'Ese celular ya tiene una cuenta de conductor.'],
            ['dni', $dni, 'Ese DNI ya está registrado.'],
            ['vehicle_plate', $placa, 'Esa placa ya está registrada con otro conductor.'],
        ] as [$campo, $valor, $mensaje]) {
            if (Driver::withTrashed()->where($campo, $valor)->exists()) {
                throw ValidationException::withMessages([$campo => $mensaje]);
            }
        }

        $driver = Driver::create([
            'code'              => Driver::makeCode(),
            'full_name'         => trim($d['full_name']),
            'phone'             => $d['phone'],
            'dni'               => $dni,
            'password'          => Hash::make($d['password']),
            'vehicle_make'      => $d['vehicle_make'],
            'vehicle_model'     => $d['vehicle_model'],
            'vehicle_plate'     => $placa,
            'vehicle_color'     => $d['vehicle_color'],
            'status'            => 'desconectado',
            'account_status'    => 'activo',
            'onboarding_status' => 'registrado',
            'self_registered'   => true,
            'saldo'             => 0,
        ]);

        $request->session()->regenerate();
        $request->session()->put('driver_id', $driver->id);

        return response()->json([
            'ok'     => true,
            'driver' => ['code' => $driver->code, 'full_name' => $driver->full_name],
            'csrf'   => csrf_token(),
        ], 201);
    }

    /** La lista de papeles con el estado de cada uno, para la pantalla de postulación. */
    public function checklist(Request $request)
    {
        $driver = $request->attributes->get('driver');

        return response()->json([
            'onboarding' => $driver->onboarding_status,
            'due_at'     => $driver->docs_due_at?->toDateString(),
            'blocked'    => DriverDocuments::blocking($driver),
            'message'    => DriverDocuments::blockMessage($driver),
            'documents'  => DriverDocuments::checklist($driver),
        ]);
    }

    public function upload(Request $request, string $key)
    {
        $driver = $request->attributes->get('driver');
        $type = DocumentType::where('key', $key)->where('active', true)->first();

        if (! $type) {
            return response()->json(['message' => 'Ese documento ya no se solicita.'], 404);
        }

        $reglas = ['file' => DriverDocuments::RULES];
        if ($type->needs_number) {
            $reglas['number'] = ['required', 'string', 'max:40'];
        }
        if ($type->has_expiry) {
            // Un documento que vence mañana no sirve de nada: se exige que quede margen.
            $reglas['expires_at'] = ['required', 'date', 'after:'.now()->addDays(7)->toDateString()];
        }

        $d = $request->validate($reglas, [
            'expires_at.after' => 'La fecha de vencimiento debe tener al menos una semana por delante.',
        ], ['file' => 'el archivo', 'number' => 'el número', 'expires_at' => 'el vencimiento']);

        DriverDocuments::submit($driver, $type, $request->file('file'),
                                $d['number'] ?? null, $d['expires_at'] ?? null);

        // Si ya no le falta nada por enviar, la postulación pasa a revisión sola.
        if ($driver->onboarding_status !== 'aprobado' && ! $this->faltaEnviar($driver)) {
            $driver->update(['onboarding_status' => 'enviado']);
        }

        return response()->json([
            'ok'         => true,
            'onboarding' => $driver->fresh()->onboarding_status,
            'documents'  => DriverDocuments::checklist($driver),
        ]);
    }

    /** ¿Queda algún documento obligatorio que ni siquiera envió? */
    private function faltaEnviar(Driver $driver): bool
    {
        $enviados = $driver->documents()
            ->whereIn('status', ['pendiente', 'aprobado'])
            ->pluck('document_type_id')
            ->all();

        return DocumentType::vigentes()->where('required', true)
            ->whereNotIn('id', $enviados ?: [0])
            ->exists();
    }
}
