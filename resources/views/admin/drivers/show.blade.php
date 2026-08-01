@extends('admin.layout')
@section('title','Conductor')
@section('content')
@php($cur = \App\Models\Setting::get('currency','S/'))

<div style="margin-bottom:14px"><a href="{{ route('admin.drivers.index') }}" class="muted">← Volver a conductores</a></div>

<div class="grid" style="grid-template-columns:1.5fr 1fr;align-items:start">
    {{-- Ficha --}}
    <div class="grid" style="gap:16px">
        <div class="card">
            <div class="between">
                <div style="display:flex;align-items:center;gap:14px">
                    <div class="avci" style="width:54px;height:54px;font-size:20px;border-radius:14px">{{ strtoupper(substr($driver->full_name,0,1)) }}</div>
                    <div>
                        <div style="font-size:19px;font-weight:700">{{ $driver->full_name }}</div>
                        <div class="muted">{{ $driver->code }} · {{ $driver->phone }} @if($driver->email)· {{ $driver->email }}@endif</div>
                        <div style="margin-top:6px;display:flex;gap:7px">
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
                        <select class="input" name="method"><option value="admin">Carga manual</option><option value="yape">Yape</option><option value="transferencia">Transferencia</option></select>
                    </div>
                </div>
                <div class="field"><label>Referencia (opcional)</label><input class="input" name="reference" placeholder="N° de operación"></div>
                <button class="btn amber" style="width:100%">Acreditar saldo</button>
            </form>
        </div>

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
