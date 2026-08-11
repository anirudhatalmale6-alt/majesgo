<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Driver;
use App\Services\VehiclePhoto;
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
        $this->syncVehiclePhoto($request, $driver);

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
        $this->syncVehiclePhoto($request, $driver);

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

    /** Sube la foto nueva del vehículo o quita la actual, según lo que venga del formulario. */
    private function syncVehiclePhoto(Request $request, Driver $driver): void
    {
        if ($request->boolean('remove_vehicle_photo')) {
            VehiclePhoto::clear($driver);
            return;
        }

        if ($request->hasFile('vehicle_photo')) {
            $driver->update(['vehicle_photo' => VehiclePhoto::store($request->file('vehicle_photo'), $driver)]);
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
            'vehicle_photo'  => VehiclePhoto::RULES,
        ], [
            'vehicle_photo.uploaded' => 'No se pudo subir la foto: pesa más de lo que admite el servidor. Usa una imagen más liviana.',
            'vehicle_photo.image'    => 'El archivo de la foto debe ser una imagen.',
            'vehicle_photo.mimes'    => 'La foto debe ser JPG, PNG o WEBP.',
            'vehicle_photo.max'      => 'La foto es muy pesada (máx. 12 MB).',
        ]);

        // el archivo se procesa aparte (syncVehiclePhoto); nunca debe llegar al update() del modelo
        unset($data['vehicle_photo']);

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
