@extends('admin.layout')
@section('title','Configuración')
@section('content')

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf @method('PUT')

    <div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
        <div class="card">
            <h3 style="margin-bottom:14px">Comisión y saldo</h3>
            <div class="field">
                <label>Comisión por carrera completada ({{ $settings['currency'] }})</label>
                <input class="input" type="number" step="0.01" min="0" name="commission_value" value="{{ old('commission_value',$settings['commission_value']) }}" required>
                <div class="muted" style="font-size:12px;margin-top:5px">Se descuenta del saldo del conductor por cada viaje completado. Ejemplo: 0.50</div>
            </div>
            <div class="field">
                <label>Montos de recarga sugeridos</label>
                <input class="input" name="saldo_tiers" value="{{ old('saldo_tiers',$settings['saldo_tiers']) }}" required>
                <div class="muted" style="font-size:12px;margin-top:5px">Separados por comas. Ej: 20,50,100</div>
            </div>
            <div class="field">
                <label>Alerta de saldo bajo ({{ $settings['currency'] }})</label>
                <input class="input" type="number" step="0.5" min="0" name="min_saldo_alert" value="{{ old('min_saldo_alert',$settings['min_saldo_alert']) }}" required>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-bottom:14px">Datos de la plataforma</h3>
            <div class="field"><label>Nombre de la app</label><input class="input" name="app_name" value="{{ old('app_name',$settings['app_name']) }}" required></div>
            <div class="field"><label>Eslogan</label><input class="input" name="app_slogan" value="{{ old('app_slogan',$settings['app_slogan']) }}"></div>
            <div class="row">
                <div class="field"><label>Ciudad</label><input class="input" name="city" value="{{ old('city',$settings['city']) }}"></div>
                <div class="field" style="max-width:110px"><label>Moneda</label><input class="input" name="currency" value="{{ old('currency',$settings['currency']) }}" required></div>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-bottom:14px">Yape para recargas</h3>
            <div class="muted" style="font-size:12.5px;margin-bottom:12px">Datos que verá el conductor al recargar su saldo por Yape.</div>
            <div class="field"><label>Número de Yape</label><input class="input" name="yape_number" value="{{ old('yape_number',$settings['yape_number']) }}" placeholder="9XXXXXXXX"></div>
            <div class="field"><label>Titular de la cuenta</label><input class="input" name="yape_holder" value="{{ old('yape_holder',$settings['yape_holder']) }}" placeholder="Nombre del titular"></div>
        </div>

        <div class="card">
            <h3 style="margin-bottom:10px">Seguridad</h3>
            <div class="muted" style="font-size:12.5px;margin-bottom:12px">Cambia la contraseña de tu cuenta de administrador.</div>
            <div id="pwbox">
                <div class="field"><label>Contraseña actual</label><input class="input" type="password" form="pwform" name="current_password"></div>
                <div class="field"><label>Nueva contraseña</label><input class="input" type="password" form="pwform" name="password"></div>
                <div class="field"><label>Repetir nueva contraseña</label><input class="input" type="password" form="pwform" name="password_confirmation"></div>
                <button class="btn ghost" form="pwform" formaction="{{ route('admin.password.update') }}" style="width:100%">Cambiar contraseña</button>
            </div>
        </div>
    </div>

    <div style="margin-top:16px"><button class="btn">Guardar configuración</button></div>
</form>

<form id="pwform" method="POST" action="{{ route('admin.password.update') }}">@csrf</form>
@endsection
