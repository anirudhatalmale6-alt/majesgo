<div class="card" style="max-width:760px">
    <h3 style="margin-bottom:14px">Datos del conductor</h3>
    <div class="row">
        <div class="field"><label>Nombre completo *</label><input class="input" name="full_name" value="{{ old('full_name',$driver->full_name) }}" required></div>
        <div class="field"><label>DNI</label><input class="input" name="dni" value="{{ old('dni',$driver->dni) }}"></div>
    </div>
    <div class="row">
        <div class="field"><label>Teléfono / WhatsApp *</label><input class="input" name="phone" value="{{ old('phone',$driver->phone) }}" placeholder="9XXXXXXXX" required></div>
        <div class="field"><label>Correo (opcional)</label><input class="input" type="email" name="email" value="{{ old('email',$driver->email) }}"></div>
        <div class="field"><label>N° de licencia</label><input class="input" name="license_number" value="{{ old('license_number',$driver->license_number) }}"></div>
    </div>

    @unless($driver->exists)
    <div class="field" style="max-width:340px"><label>Contraseña de la app del conductor *</label><input class="input" type="text" name="password" value="{{ old('password') }}" placeholder="mín. 6 caracteres" required>
        <div class="muted" style="font-size:12px;margin-top:5px">El conductor la usará para ingresar a su app. Podrás restablecerla luego.</div>
    </div>
    @endunless

    <h3 style="margin:18px 0 14px">Vehículo</h3>
    <div class="row">
        <div class="field"><label>Marca</label><input class="input" name="vehicle_make" value="{{ old('vehicle_make',$driver->vehicle_make) }}" placeholder="Toyota"></div>
        <div class="field"><label>Modelo</label><input class="input" name="vehicle_model" value="{{ old('vehicle_model',$driver->vehicle_model) }}" placeholder="Yaris"></div>
        <div class="field"><label>Placa</label><input class="input" name="vehicle_plate" value="{{ old('vehicle_plate',$driver->vehicle_plate) }}" placeholder="V7A-482"></div>
    </div>
    <div class="row">
        <div class="field"><label>Color</label><input class="input" name="vehicle_color" value="{{ old('vehicle_color',$driver->vehicle_color) }}" placeholder="Blanco"></div>
        <div class="field"><label>Año</label><input class="input" name="vehicle_year" value="{{ old('vehicle_year',$driver->vehicle_year) }}" placeholder="2020"></div>
        <div class="field"></div>
    </div>

    <div style="display:flex;gap:10px;margin-top:8px">
        <button class="btn">{{ $driver->exists ? 'Guardar cambios' : 'Crear conductor' }}</button>
        <a href="{{ $driver->exists ? route('admin.drivers.show',$driver) : route('admin.drivers.index') }}" class="btn ghost">Cancelar</a>
    </div>
</div>
