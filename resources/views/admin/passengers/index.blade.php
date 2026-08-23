@extends('admin.layout')
@section('title','Pasajeros')

@section('content')

<div class="row" style="margin-bottom:16px">
    <div class="card"><div class="muted" style="font-size:12px">Pasajeros registrados</div><div style="font-size:26px;font-weight:800">{{ $resumen['total'] }}</div></div>
    <div class="card"><div class="muted" style="font-size:12px">Con cuenta activa</div><div style="font-size:26px;font-weight:800;color:#00a344">{{ $resumen['activos'] }}</div></div>
    <div class="card"><div class="muted" style="font-size:12px">Suspendidos o bloqueados</div><div style="font-size:26px;font-weight:800;color:{{ $resumen['bloqueados'] ? '#c0322b' : 'inherit' }}">{{ $resumen['bloqueados'] }}</div></div>
    <div class="card"><div class="muted" style="font-size:12px">Nuevos (últimos 7 días)</div><div style="font-size:26px;font-weight:800">{{ $resumen['nuevos_7d'] }}</div></div>
</div>

<div class="between" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:8px">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Buscar por nombre o teléfono…" style="width:320px">
        <select class="input" name="estado" style="width:170px" onchange="this.form.submit()">
            <option value="">Todos los estados</option>
            <option value="activo" @selected(request('estado')=='activo')>Activos</option>
            <option value="suspendido" @selected(request('estado')=='suspendido')>Suspendidos</option>
            <option value="bloqueado" @selected(request('estado')=='bloqueado')>Bloqueados</option>
        </select>
        <button class="btn ghost">Buscar</button>
    </form>
    <div class="muted" style="font-size:12.5px">Las cuentas de pasajero se crean solas desde la app.</div>
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr>
            <th>Pasajero</th><th>Cuenta</th><th style="text-align:center">Viajes</th>
            <th style="text-align:center">Cancelados</th><th style="text-align:center">Calificación</th>
            <th>Desde</th><th>Última actividad</th><th>Registro</th><th></th>
        </tr></thead>
        <tbody>
        @forelse($passengers as $p)
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:11px">
                        <div class="avci">{{ strtoupper(substr($p->name,0,1)) }}</div>
                        <div><div style="font-weight:600">{{ $p->name }}</div><div class="muted" style="font-size:12px">{{ $p->phone }}</div></div>
                    </div>
                </td>
                <td>
                    @php($acls=['activo'=>'on','suspendido'=>'sus','bloqueado'=>'blk'][$p->account_status]??'off')
                    <span class="badge {{ $acls }}">{{ $p->accountLabel() }}</span>
                </td>
                <td style="text-align:center">{{ $p->viajes_ok }}</td>
                <td style="text-align:center">
                    @if($p->viajes_cancel)
                        <span style="color:{{ $p->viajes_cancel >= 5 ? '#c0322b' : 'inherit' }};font-weight:{{ $p->viajes_cancel >= 5 ? '700' : '400' }}">{{ $p->viajes_cancel }}</span>
                    @else — @endif
                </td>
                <td style="text-align:center">
                    {{-- Un pasajero recién registrado arranca en 5.00 sin que nadie lo haya
                         calificado. Mostrar 5 estrellas ahí es inventarle una reputación. --}}
                    @if($p->viajes_ok > 0) ⭐ {{ number_format($p->rating,1) }} @else <span class="muted">Sin calificar</span> @endif
                </td>
                {{-- Desde dónde usa la app: sirve para saber quién ya instaló la de Play --}}
                <td>
                    @if($p->app_source === 'play')
                        <span class="badge on">App de Play</span>
                    @elseif($p->app_source === 'web')
                        <span class="badge off">Navegador</span>
                    @else
                        <span class="muted">Nunca entró</span>
                    @endif
                </td>
                <td class="muted" style="font-size:12.5px">{{ $p->last_active_at ? $p->last_active_at->diffForHumans() : 'Nunca entró' }}</td>
                <td class="muted" style="font-size:12.5px">{{ $p->created_at->format('d/m/Y') }}</td>
                <td style="text-align:right;white-space:nowrap">
                    <a href="{{ route('admin.passengers.show',$p) }}" class="btn ghost sm">Ver</a>
                    @if($p->account_status=='activo')
                        <form method="POST" action="{{ route('admin.passengers.status',$p) }}" style="display:inline"
                              onsubmit="return confirm('Suspender a {{ $p->name }}: no podrá pedir taxis ni entrar a la app. Puedes reactivarlo cuando quieras. ¿Continuar?')">
                            @csrf
                            <input type="hidden" name="account_status" value="suspendido">
                            <button class="btn ghost sm">Suspender</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.passengers.status',$p) }}" style="display:inline">@csrf
                            <input type="hidden" name="account_status" value="activo">
                            <button class="btn ghost sm">Reactivar</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="muted" style="text-align:center;padding:34px">
                @if(request('q') || request('estado'))
                    No se encontraron pasajeros con ese criterio.
                @else
                    Todavía no se registró ningún pasajero. Aparecerán aquí solos, apenas creen su cuenta desde la app.
                @endif
            </td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="pagi">{{ $passengers->links() }}</div>
@endsection
