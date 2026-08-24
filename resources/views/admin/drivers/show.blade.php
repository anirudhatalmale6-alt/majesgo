@extends('admin.layout')
@section('title','Conductor')
@section('content')
@php($cur = \App\Models\Setting::get('currency','S/'))

<div style="margin-bottom:14px"><a href="{{ route('admin.drivers.index') }}" class="muted">← Volver a conductores</a></div>

<div class="grid split" style="grid-template-columns:1.5fr 1fr;align-items:start">
    {{-- Ficha --}}
    <div class="grid" style="gap:16px">
        <div class="card">
            <div class="between">
                <div style="display:flex;align-items:center;gap:14px;min-width:0">
                    <div class="avci" style="width:54px;height:54px;font-size:20px;border-radius:14px">{{ strtoupper(substr($driver->full_name,0,1)) }}</div>
                    <div>
                        <div style="font-size:19px;font-weight:700">{{ $driver->full_name }}</div>
                        <div class="muted">{{ $driver->code }} · {{ $driver->phone }} @if($driver->email)· {{ $driver->email }}@endif</div>
                        <div style="margin-top:6px;display:flex;gap:7px;flex-wrap:wrap">
                            @php($cls=['disponible'=>'on','ocupado'=>'busy','desconectado'=>'off'][$driver->status]??'off')
                            <span class="badge {{ $cls }}"><span class="dot"></span>{{ $driver->statusLabel() }}</span>
                            @php($acls=['activo'=>'on','suspendido'=>'sus','bloqueado'=>'blk'][$driver->account_status]??'off')
                            <span class="badge {{ $acls }}">{{ $driver->accountLabel() }}</span>
                            <span class="badge off">⭐ {{ number_format($driver->rating,1) }}</span>
                        </div>
                    </div>
                </div>
                <a href="{{ route('admin.drivers.edit',$driver) }}" class="btn ghost sm">Editar</a>
            </div>
            <div class="row" style="margin-top:16px">
                <div><div class="muted" style="font-size:12px">Vehículo</div><div style="font-weight:600">{{ $driver->vehicleSummary() ?: '—' }}</div></div>
                <div><div class="muted" style="font-size:12px">Color</div><div style="font-weight:600">{{ $driver->vehicle_color ?: '—' }}</div></div>
                <div><div class="muted" style="font-size:12px">Viajes completados</div><div style="font-weight:600">{{ $driver->total_trips }}</div></div>
                <div><div class="muted" style="font-size:12px">Licencia</div><div style="font-weight:600">{{ $driver->license_number ?: '—' }}</div></div>
            </div>
            @php($photoStates = \App\Services\DriverPhotos::states($driver))
            @php($missingPhotos = \App\Services\DriverPhotos::missing($driver))

            <div style="margin-top:18px">
                <div class="between" style="margin-bottom:9px">
                    <div class="muted" style="font-size:12px">Fotos publicadas (las que ve el pasajero)</div>
                    @if(\App\Services\DriverPhotos::required())
                        <span class="badge {{ $missingPhotos ? 'blk' : 'on' }}">
                            {{ $missingPhotos ? '✗ No puede conectarse: faltan fotos aprobadas' : '✓ Fotos aprobadas' }}
                        </span>
                    @endif
                </div>

                <div style="display:flex;gap:16px;flex-wrap:wrap">
                    @foreach(['perfil'=>['Rostro','130px'],'vehiculo'=>['Vehículo','260px']] as $t => $meta)
                        @php($st = $photoStates[$t])
                        <div>
                            <div class="muted" style="font-size:11.5px;margin-bottom:5px">{{ $meta[0] }}</div>
                            @if($st['url'])
                                <img src="{{ $st['url'] }}" alt="{{ $meta[0] }} de {{ $driver->full_name }}"
                                     style="width:{{ $meta[1] }};max-width:100%;height:176px;object-fit:cover;border-radius:12px;border:1px solid var(--line);display:block">
                            @else
                                <div style="width:{{ $meta[1] }};max-width:100%;height:176px;border-radius:12px;border:1px dashed var(--line);display:grid;place-items:center;color:var(--muted);font-size:12.5px;text-align:center;padding:10px">
                                    Sin foto publicada
                                </div>
                            @endif
                            <div style="margin-top:7px;font-size:12px">
                                @if($st['status']=='pendiente')
                                    <span class="badge sus">Nueva foto pendiente de revisión</span>
                                @elseif($st['status']=='rechazada')
                                    <span class="badge blk">Rechazada</span>
                                @endif
                                @if($st['reason'])
                                    <div class="muted" style="margin-top:5px">Motivo: {{ $st['reason'] }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                @if($photoStates['perfil']['status']=='pendiente' || $photoStates['vehiculo']['status']=='pendiente')
                    <a href="{{ route('admin.photos.index') }}" class="btn ghost sm" style="margin-top:12px;width:auto;display:inline-block">Revisar fotos pendientes</a>
                @endif
            </div>
        </div>

        {{-- Historial de saldo --}}
        <div class="card" style="padding:0">
            <div class="between" style="padding:16px 18px 6px"><h3>Movimientos de saldo</h3></div>
            <table>
                <thead><tr><th>Fecha</th><th>Concepto</th><th style="text-align:right">Monto</th><th style="text-align:right">Saldo</th></tr></thead>
                <tbody>
                @forelse($driver->movements as $m)
                    <tr>
                        <td class="muted" style="font-size:12.5px">{{ $m->created_at->format('d/m H:i') }}</td>
                        <td>
                            @php($mi=['recarga'=>['on','Recarga'],'comision'=>['blk','Comisión'],'ajuste'=>['sus','Ajuste']][$m->type]??['off',$m->type])
                            <span class="badge {{ $mi[0] }}">{{ $mi[1] }}</span> <span class="muted" style="font-size:12.5px">{{ $m->description }}</span>
                        </td>
                        <td style="text-align:right;font-weight:700;color:{{ $m->amount<0?'#c0322b':'#00a344' }}">{{ $m->amount<0?'−':'+' }}{{ $cur }} {{ number_format(abs($m->amount),2) }}</td>
                        <td style="text-align:right">{{ $cur }} {{ number_format($m->balance_after,2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted" style="text-align:center;padding:22px">Sin movimientos todavía.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Panel lateral de acciones --}}
    <div class="grid" style="gap:16px;align-content:start">
        <div class="card" style="background:linear-gradient(135deg,#00c853,#00a344);color:#fff;border:0">
            <div style="font-size:12.5px;opacity:.9">Saldo disponible</div>
            <div style="font-size:34px;font-weight:800;margin:2px 0">{{ $cur }} {{ number_format($driver->saldo,2) }}</div>
            <div style="font-size:12.5px;opacity:.92">
                @if($driver->canReceiveRides()) ✓ Puede recibir viajes @else ✗ Sin saldo suficiente — no recibe viajes @endif
            </div>
        </div>

        {{-- Recarga / ajuste --}}
        <div class="card">
            <h3 style="margin-bottom:12px">Recargar saldo</h3>
            <form method="POST" action="{{ route('admin.recharges.store') }}">
                @csrf
                <input type="hidden" name="driver_id" value="{{ $driver->id }}">
                <div class="row">
                    <div class="field"><label>Monto ({{ $cur }})</label><input class="input" name="amount" type="number" step="0.5" min="0.5" placeholder="20.00" required></div>
                    <div class="field"><label>Método</label>
                        <select class="input" name="method"><option value="admin">Carga manual</option><option value="yape">Yape</option><option value="plin">Plin</option><option value="transferencia">Transferencia</option></select>
                    </div>
                </div>
                <div class="field"><label>Referencia (opcional)</label><input class="input" name="reference" placeholder="N° de operación"></div>
                <button class="btn amber" style="width:100%">Acreditar saldo</button>
            </form>
        </div>

        {{-- Corregir saldo: para cuando la central se equivocó al cargarlo --}}
        <div class="card">
            <h3 style="margin-bottom:4px">Corregir saldo</h3>
            <div class="muted" style="font-size:12px;margin-bottom:12px">
                Si te equivocaste al cargar el saldo, aquí lo bajas o lo dejas en el número correcto.
            </div>
            <form method="POST" action="{{ route('admin.drivers.saldo',$driver) }}" id="fix-saldo">
                @csrf
                <div class="row">
                    <div class="field"><label>Qué quieres hacer</label>
                        <select class="input" name="mode" id="fs-mode">
                            <option value="fijar">Dejar el saldo en…</option>
                            <option value="descontar">Descontar…</option>
                        </select>
                    </div>
                    <div class="field"><label>Monto ({{ $cur }})</label>
                        <input class="input" name="value" id="fs-value" type="number" step="0.5" min="0" placeholder="0.00" required>
                    </div>
                </div>
                <div class="field" style="margin-bottom:10px"><label>Motivo (opcional)</label>
                    <input class="input" name="note" id="fs-note" maxlength="255" placeholder="Ej.: cargué 30 por error, eran 20">
                </div>
                <div class="muted" id="fs-hint" style="font-size:12.5px;margin-bottom:12px">
                    Saldo actual: <b>{{ $cur }} {{ number_format($driver->saldo,2) }}</b>
                </div>
                <button class="btn ghost" style="width:100%">Guardar corrección</button>
            </form>
            <div class="muted" style="font-size:11.5px;margin-top:10px;line-height:1.5">
                No se borra nada del historial: el movimiento equivocado se queda y debajo aparece la
                corrección, para que después se entienda qué pasó.
            </div>
        </div>

        <script>
        (function(){
            var actual = {{ (float) $driver->saldo }};
            var sim = '{{ $cur }}';
            var mode = document.getElementById('fs-mode'),
                val  = document.getElementById('fs-value'),
                hint = document.getElementById('fs-hint'),
                form = document.getElementById('fix-saldo');
            if(!mode) return;

            function money(n){ return sim + ' ' + n.toFixed(2); }

            // Lo que va a pasar, escrito antes de pulsar el botón: el error de tipeo
            // se ve aquí y no en el historial del conductor.
            function paint(){
                var v = parseFloat(val.value);
                if(isNaN(v)){
                    hint.innerHTML = 'Saldo actual: <b>' + money(actual) + '</b>';
                    hint.style.color = ''; return;
                }
                var target = mode.value === 'fijar' ? v : Math.round((actual - v)*100)/100;
                var delta  = Math.round((target - actual)*100)/100;
                if(target < 0){
                    hint.innerHTML = 'Eso dejaría el saldo en ' + money(target) +
                        '. No puede quedar negativo: lo máximo que puedes descontar es <b>' + money(actual) + '</b>.';
                    hint.style.color = '#c0322b'; return;
                }
                hint.style.color = '';
                if(Math.abs(delta) < 0.005){
                    hint.innerHTML = 'El saldo ya está en <b>' + money(actual) + '</b>, no hay nada que corregir.';
                    return;
                }
                hint.innerHTML = 'Queda en <b>' + money(target) + '</b> · se anota un ajuste de <b>' +
                    (delta < 0 ? '−' : '+') + money(Math.abs(delta)) + '</b>';
            }

            mode.addEventListener('change', paint);
            val.addEventListener('input', paint);

            form.addEventListener('submit', function(e){
                var v = parseFloat(val.value);
                if(isNaN(v)) return;
                var target = mode.value === 'fijar' ? v : Math.round((actual - v)*100)/100;
                if(!confirm('El saldo de {{ addslashes($driver->full_name) }} pasa de ' +
                            money(actual) + ' a ' + money(target) + '.\n\n¿Confirmas?')) e.preventDefault();
            });
        })();
        </script>

        {{-- Estado de cuenta --}}
        <div class="card">
            <h3 style="margin-bottom:10px">Estado de la cuenta</h3>
            <form method="POST" action="{{ route('admin.drivers.status',$driver) }}" style="display:flex;gap:8px">
                @csrf
                <select class="input" name="account_status">
                    <option value="activo" @selected($driver->account_status=='activo')>Activo</option>
                    <option value="suspendido" @selected($driver->account_status=='suspendido')>Suspendido</option>
                    <option value="bloqueado" @selected($driver->account_status=='bloqueado')>Bloqueado</option>
                </select>
                <button class="btn ghost">Aplicar</button>
            </form>
            <div class="muted" style="font-size:12px;margin-top:8px">Suspendido/Bloqueado deja de recibir viajes al instante.</div>
        </div>

        {{-- Dar de baja --}}
        <div class="card">
            <h3 style="margin-bottom:10px">Dar de baja</h3>
            <div class="muted" style="font-size:12.5px;margin-bottom:12px;line-height:1.5">
                Deja de aparecer en el panel y no puede entrar a la app.
                <b>Sus viajes, recargas y movimientos de saldo se conservan</b>, y puedes restaurarlo
                cuando quieras desde el filtro «Dados de baja» en la lista de conductores.
            </div>
            <form method="POST" action="{{ route('admin.drivers.destroy',$driver) }}"
                  onsubmit="return confirm('Dar de baja a {{ $driver->full_name }}. Podrás restaurarlo después. ¿Continuar?')">
                @csrf @method('DELETE')
                <button class="btn danger" style="width:100%">Dar de baja a este conductor</button>
            </form>
        </div>

        {{-- Reset password --}}
        <div class="card">
            <h3 style="margin-bottom:10px">Restablecer contraseña</h3>
            <form method="POST" action="{{ route('admin.drivers.password',$driver) }}">
                @csrf
                <div class="field"><input class="input" type="text" name="password" placeholder="Nueva contraseña" required></div>
                <div class="field"><input class="input" type="text" name="password_confirmation" placeholder="Repetir contraseña" required></div>
                <button class="btn ghost" style="width:100%">Actualizar contraseña</button>
            </form>
        </div>
    </div>
</div>
@endsection
