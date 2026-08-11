@extends('admin.layout')
@section('title','Configuración')
@section('content')

<form method="POST" action="{{ route('admin.settings.update') }}">
    @csrf @method('PUT')

    <div class="grid" style="grid-template-columns:1fr 1fr;align-items:start">
        <div class="card">
            <h3 style="margin-bottom:14px">Tarifa, comisión y saldo</h3>
            <div class="row">
                <div class="field">
                    <label>Precio por minuto ({{ $settings['currency'] }})</label>
                    <input class="input" type="number" step="0.10" min="0.1" name="fare_per_min" value="{{ old('fare_per_min',$settings['fare_per_min']) }}" required>
                </div>
                <div class="field">
                    <label>Tarifa mínima ({{ $settings['currency'] }})</label>
                    <input class="input" type="number" step="0.50" min="0.5" name="fare_min" value="{{ old('fare_min',$settings['fare_min']) }}" required>
                </div>
            </div>
            <div class="muted" style="font-size:12px;margin:-6px 0 14px">
                La tarifa se calcula por el tiempo del viaje. Cualquier carrera de hasta
                {{ (int) ceil((float)$settings['fare_min'] / max(0.01,(float)$settings['fare_per_min'])) }} minutos
                cuesta la tarifa mínima; a partir de ahí se suma el precio por minuto.
                Ejemplo con los valores actuales: 8 min = {{ $settings['currency'] }} {{ number_format((float)$settings['fare_min'],2) }} ·
                12 min = {{ $settings['currency'] }} {{ number_format(max((float)$settings['fare_min'], 12*(float)$settings['fare_per_min']),2) }}
            </div>
            <div class="field">
                <label>Comisión de la app (%)</label>
                <input class="input" type="number" step="0.5" min="0" max="50" name="commission_pct" value="{{ old('commission_pct',$settings['commission_pct']) }}" required>
                <div class="muted" style="font-size:12px;margin-top:5px">
                    Porcentaje de la tarifa que se descuenta del saldo del conductor al completar el viaje.
                    Con {{ rtrim(rtrim(number_format((float)$settings['commission_pct'],2,'.',''),'0'),'.') }}%, una carrera de
                    {{ $settings['currency'] }} {{ number_format((float)$settings['fare_min'],2) }} deja
                    {{ $settings['currency'] }} {{ number_format((float)$settings['fare_min'] * (float)$settings['commission_pct'] / 100, 2) }}
                    para la app y {{ $settings['currency'] }} {{ number_format((float)$settings['fare_min'] * (100-(float)$settings['commission_pct']) / 100, 2) }} para el conductor.
                </div>
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
            <h3 style="margin-bottom:14px">Datos de pago para recargas</h3>
            <div class="muted" style="font-size:12.5px;margin-bottom:14px">
                Esto es lo que ve el conductor en su app al recargar saldo, con botón de copiar en cada dato.
                Deja en blanco el medio que no uses y no aparecerá en la app.
            </div>

            <div style="font-weight:700;font-size:13px;margin:2px 0 10px">💜 Yape</div>
            <div class="row">
                <div class="field"><label>Número de Yape</label><input class="input" name="yape_number" value="{{ old('yape_number',$settings['yape_number']) }}" placeholder="9XXXXXXXX"></div>
                <div class="field"><label>Titular</label><input class="input" name="yape_holder" value="{{ old('yape_holder',$settings['yape_holder']) }}" placeholder="Nombre del titular"></div>
            </div>

            <div style="font-weight:700;font-size:13px;margin:14px 0 10px">💙 Plin</div>
            <div class="row">
                <div class="field"><label>Número de Plin</label><input class="input" name="plin_number" value="{{ old('plin_number',$settings['plin_number']) }}" placeholder="9XXXXXXXX"></div>
                <div class="field"><label>Titular</label><input class="input" name="plin_holder" value="{{ old('plin_holder',$settings['plin_holder']) }}" placeholder="Nombre del titular"></div>
            </div>

            <div style="font-weight:700;font-size:13px;margin:14px 0 10px">🏦 Transferencia bancaria</div>
            <div class="row">
                <div class="field"><label>Banco</label><input class="input" name="bank_name" value="{{ old('bank_name',$settings['bank_name']) }}" placeholder="BCP, Interbank, BBVA…"></div>
                <div class="field"><label>Titular</label><input class="input" name="bank_holder" value="{{ old('bank_holder',$settings['bank_holder']) }}" placeholder="Nombre del titular"></div>
            </div>
            <div class="row">
                <div class="field"><label>Número de cuenta</label><input class="input" name="bank_account" value="{{ old('bank_account',$settings['bank_account']) }}" placeholder="000-00000000-0-00"></div>
                <div class="field"><label>CCI (interbancario)</label><input class="input" name="bank_cci" value="{{ old('bank_cci',$settings['bank_cci']) }}" placeholder="00200000000000000000"></div>
            </div>

            <div class="field" style="margin-top:14px">
                <label>Mensaje adicional (opcional)</label>
                <textarea class="input" name="recharge_note" rows="2" placeholder="Ej: Las recargas se validan de 8am a 10pm.">{{ old('recharge_note',$settings['recharge_note']) }}</textarea>
                <div class="muted" style="font-size:12px;margin-top:5px">Se muestra al conductor debajo de los datos de pago.</div>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-bottom:14px">Fotos de conductores</h3>
            <div class="field">
                <label style="display:flex;align-items:center;gap:9px;cursor:pointer">
                    <input type="checkbox" name="require_photos" value="1" {{ old('require_photos',$settings['require_photos'])=='1' ? 'checked' : '' }} style="width:auto">
                    <span>Exigir foto de perfil y de vehículo aprobadas para conectarse</span>
                </label>
                <div class="muted" style="font-size:12px;margin-top:5px;line-height:1.5">
                    Con esto activado, un conductor no puede ponerse Disponible hasta que apruebes sus dos fotos en la sección «Fotos».
                    Si lo desactivas, las fotos se siguen revisando igual (nada se publica sin tu aprobación), pero puede trabajar mientras tanto.
                </div>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-bottom:14px">Despacho de viajes</h3>
            <div class="field">
                <label>Radio de búsqueda de conductores (km)</label>
                <input class="input" type="number" step="0.5" min="0.5" name="dispatch_radius_km" value="{{ old('dispatch_radius_km',$settings['dispatch_radius_km']) }}" required>
                <div class="muted" style="font-size:12px;margin-top:5px">A qué distancia del pasajero se avisa a los conductores. Ej: 3</div>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:9px;cursor:pointer">
                    <input type="checkbox" name="demo_enabled" value="1" {{ old('demo_enabled',$settings['demo_enabled'])=='1' ? 'checked' : '' }} style="width:auto">
                    <span>Conductor de prueba (demo) cuando no hay conductores conectados</span>
                </label>
                <div class="muted" style="font-size:12px;margin-top:5px">Útil para probar la app del pasajero solo. Desactívalo para una prueba real con conductor. Si hay un conductor real conectado, el demo nunca interviene.</div>
            </div>
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
