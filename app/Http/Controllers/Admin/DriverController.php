<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Services\DriverPhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DriverController extends Controller
{
    public function index(Request $request)
    {
        $q = Driver::query();

        if ($search = $request->get('q')) {
            $q->where(function ($w) use ($search) {
                $w->where('full_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('vehicle_plate', 'like', "%{$search}%");
            });
        }
        if ($status = $request->get('estado')) {
            $q->where('account_status', $status);
        }

        $drivers = $q->latest()->paginate(15)->withQueryString();

        return view('admin.drivers.index', compact('drivers'));
    }

    public function create()
    {
        return view('admin.drivers.create', ['driver' => new Driver()]);
    }

    public function store(Request $request)
    {
        $data = $this->validateDriver($request);
        $request->validate(['saldo' => ['nullable', 'numeric', 'min:0', 'max:100000']]);

        // normalizar el teléfono a 9 dígitos (sin código de país/espacios) para que el login coincida siempre
        $digits = preg_replace('/\D/', '', $data['phone']);
        $data['phone'] = strlen($digits) > 9 ? substr($digits, -9) : $digits;

        $data['code']       = $this->nextCode();
        $data['password']   = Hash::make($data['password']);
        $data['created_by'] = $request->user()->id;

        $driver = Driver::create($data);
        $this->syncPhotos($request, $driver);

        // saldo inicial opcional (queda registrado en el historial de movimientos)
        $initial = round((float) $request->input('saldo', 0), 2);
        if ($initial > 0) {
            $driver->applyMovement('ajuste', $initial, 'Saldo inicial', 'manual', null, $request->user()->id);
        }

        return redirect()->route('admin.drivers.show', $driver)
            ->with('ok', "Conductor {$driver->full_name} creado. Código {$driver->code}.");
    }

    public function show(Driver $driver)
    {
        $driver->load(['movements' => fn ($m) => $m->take(15), 'recharges' => fn ($r) => $r->latest()->take(10)]);
        return view('admin.drivers.show', compact('driver'));
    }

    public function edit(Driver $driver)
    {
        return view('admin.drivers.edit', compact('driver'));
    }

    public function update(Request $request, Driver $driver)
    {
        $data = $this->validateDriver($request, $driver);
        unset($data['password']); // la contraseña se cambia aparte
        $driver->update($data);
        $this->syncPhotos($request, $driver);

        return redirect()->route('admin.drivers.show', $driver)->with('ok', 'Datos actualizados.');
    }

    /** Activar / suspender / bloquear */
    public function setAccountStatus(Request $request, Driver $driver)
    {
        $data = $request->validate([
            'account_status' => ['required', 'in:activo,suspendido,bloqueado'],
        ]);
        $driver->update($data);

        return back()->with('ok', "Cuenta ahora: {$driver->accountLabel()}.");
    }

    public function resetPassword(Request $request, Driver $driver)
    {
        $data = $request->validate([
            'password' => ['required', 'min:6', 'confirmed'],
        ]);
        $driver->update(['password' => Hash::make($data['password'])]);

        return back()->with('ok', 'Contraseña del conductor restablecida.');
    }

    /** Ajuste manual de saldo (carga o descuento por parte del admin) */
    public function adjustSaldo(Request $request, Driver $driver)
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0'],
            'note'   => ['nullable', 'string', 'max:255'],
        ]);

        $driver->applyMovement(
            'ajuste',
            (float) $data['amount'],
            $data['note'] ?: 'Ajuste manual del administrador',
            'manual',
            null,
            $request->user()->id
        );

        return back()->with('ok', 'Saldo ajustado. Nuevo saldo: S/ ' . number_format($driver->saldo, 2));
    }

    public function destroy(Driver $driver)
    {
        $name = $driver->full_name;
        $driver->delete();
        return redirect()->route('admin.drivers.index')->with('ok', "Conductor {$name} eliminado.");
    }

    /* ---------- helpers ---------- */

    /**
     * Sube o quita las fotos desde el panel.
     *
     * Lo que carga la central queda aprobado de una vez: ella es la que aprueba.
     * Igual se registra en driver_photos para que el historial de la foto vigente sea uno solo.
     */
    private function syncPhotos(Request $request, Driver $driver): void
    {
        $fields = [
            'vehiculo' => ['file' => 'vehicle_photo', 'remove' => 'remove_vehicle_photo'],
            'perfil'   => ['file' => 'profile_photo', 'remove' => 'remove_profile_photo'],
        ];

        foreach ($fields as $type => $f) {
            $column = DriverPhotos::liveColumn($type);

            if ($request->boolean($f['remove'])) {
                \App\Services\ImageStore::delete($driver->{$column});
                $driver->photos()->where('type', $type)->where('status', 'aprobado')->delete();
                $driver->update([$column => null]);
                continue;
            }

            if ($request->hasFile($f['file'])) {
                $photo = DriverPhotos::submit($driver, $type, $request->file($f['file']));
                DriverPhotos::approve($photo, $request->user()->id);
            }
        }
    }

    private function validateDriver(Request $request, Driver $driver = null): array
    {
        $id = $driver?->id;

        $data = $request->validate([
            'full_name'      => ['required', 'string', 'max:120'],
            'dni'            => ['nullable', 'string', 'max:20'],
            'phone'          => ['required', 'string', 'max:20', "unique:drivers,phone,{$id}"],
            'email'          => ['nullable', 'email', 'max:120'],
            'password'       => [$driver ? 'nullable' : 'required', 'min:6'],
            'license_number' => ['nullable', 'string', 'max:40'],
            'vehicle_make'   => ['nullable', 'string', 'max:40'],
            'vehicle_model'  => ['nullable', 'string', 'max:40'],
            'vehicle_plate'  => ['nullable', 'string', 'max:15'],
            'vehicle_color'  => ['nullable', 'string', 'max:30'],
            'vehicle_year'   => ['nullable', 'string', 'max:5'],
            'vehicle_photo'  => DriverPhotos::RULES,
            'profile_photo'  => DriverPhotos::RULES,
        ], array_merge(
            \App\Services\ImageStore::messages('vehicle_photo', 'la foto del vehículo'),
            \App\Services\ImageStore::messages('profile_photo', 'la foto de perfil')
        ));

        // los archivos se procesan aparte (syncPhotos); nunca deben llegar al update() del modelo
        unset($data['vehicle_photo'], $data['profile_photo']);

        return $data;
    }

    private function nextCode(): string
    {
        // mayor sufijo numérico entre los códigos con forma MG-#### (ignora MG-DEMO u otros no numéricos)
        $max = Driver::pluck('code')
            ->filter(fn ($c) => preg_match('/^MG-\d+$/', (string) $c))
            ->map(fn ($c) => (int) Str::afterLast($c, '-'))
            ->max() ?? 0;
        return 'MG-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }
}
