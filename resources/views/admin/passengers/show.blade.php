@extends('admin.layout')
@section('title','Pasajero')
@section('content')
@php($cur = \App\Models\Setting::get('currency','S/'))

<div style="margin-bottom:14px"><a href="{{ route('admin.passengers.index') }}" class="muted">← Volver a pasajeros</a></div>

<div class="grid" style="grid-template-columns:1.5fr 1fr;align-items:start">

    <div class="grid" style="gap:16px">
        {{-- Ficha --}}
        <div class="card">
            <div style="display:flex;align-items:center;gap:14px">
                <div class="avci" style="width:54px;height:54px;font-size:20px;border-radius:14px">{{ strtoupper(substr($passenger->name,0,1)) }}</div>
                <div>
                    <div style="font-size:19px;font-weight:700">{{ $passenger->name }}</div>
                    <div class="muted">{{ $passenger->phone }}</div>
                    <div style="margin-top:6px;display:flex;gap:7px;flex-wrap:wrap">
                        @php($acls=['activo'=>'on','suspendido'=>'sus','bloqueado'=>'blk'][$passenger->account_status]??'off')
                        <span class="badge {{ $acls }}">{{ $passenger->accountLabel() }}</span>
                        @if($stats['calificaciones'])
                            <span class="badge off">⭐ {{ number_format($passenger->rating,1) }} · {{ $stats['calificaciones'] }} {{ $stats['calificaciones']==1?'calificación':'calificaciones' }}</span>
                        @else
                            <span class="badge off">Sin calificaciones todavía</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="row" style="margin-top:16px">
                <div><div class="muted" style="font-size:12px">Viajes completados</div><div style="font-weight:600;font-size:17px">{{ $stats['completados'] }}</div></div>
                <div><div class="muted" style="font-size:12px">Cancelados</div><div style="font-weight:600;font-size:17px;color:{{ $stats['cancelados']>=5?'#c0322b':'inherit' }}">{{ $stats['cancelados'] }}</div></div>
                <div><div class="muted" style="font-size:12px">Sin conductor</div><div style="font-weight:600;font-size:17px">{{ $stats['sin_conductor'] }}</div></div>
                <div><div class="muted" style="font-size:12px">Total gastado</div><div style="font-weight:600;font-size:17px">{{ $cur }} {{ number_format($stats['gastado'],2) }}</div></div>
            </div>

            <div class="row" style="margin-top:14px">
                <div><div class="muted" style="font-size:12px">Se registró</div><div style="font-weight:600">{{ $passenger->created_at->format('d/m/Y H:i') }}</div></div>
                <div><div class="muted" style="font-size:12px">Última vez en la app</div><div style="font-weight:600">{{ $passenger->last_active_at ? $passenger->last_active_at->format('d/m/Y H:i') : 'Nunca entró' }}</div></div>
            </div>

            @if($stats['demo'])
                <div class="muted" style="font-size:12px;margin-top:14px">
                    Los números de arriba son solo de viajes reales. Este pasajero además tiene
                    {{ $stats['demo'] }} {{ $stats['demo']==1?'viaje de prueba':'viajes de prueba' }} (con conductor simulado),
                    que sí aparecen en el historial marcados como «Prueba».
                </div>
            @endif
        </div>

        {{-- Historial de viajes --}}
        <div class="card" style="padding:0">
            <div class="between" style="padding:16px 18px 6px"><h3>Historial de viajes</h3>
                <span class="muted" style="font-size:12.5px">{{ $rides->total() }} en total</span>
            </div>
            <table>
                <thead><tr><th>Fecha</th><th>Recorrido</th><th>Conductor</th><th style="text-align:right">Precio</th><th>Pago</th><th>Resultado</th></tr></thead>
                <tbody>
                @forelse($rides as $r)
                    <tr>
                        <td class="muted" style="font-size:12.5px;white-space:nowrap">
                            {{ $r->created_at->format('d/m/Y') }}<br>{{ $r->created_at->format('H:i') }}
                            <div style="font-size:11px;margin-top:2px">{{ $r->code }}</div>
                        </td>
                        <td style="max-width:280px">
                            <div style="font-size:12.5px"><span style="color:#00a344">●</span> {{ $r->origin_address ?: 'Punto en el mapa' }}</div>
                            <div style="font-size:12.5px"><span style="color:#c0322b">●</span> {{ $r->dest_address ?: 'Punto en el mapa' }}</div>
                        </td>
                        <td style="font-size:12.5px">
                            @if($r->driver)
                                <a href="{{ route('admin.drivers.show',$r->driver) }}" style="color:var(--verde-d);font-weight:600">{{ $r->driver->full_name }}</a>
                                <div class="muted" style="font-size:11.5px">{{ $r->driver->vehicle_plate ?: $r->driver->code }}</div>
                            @else
                                <span class="muted">—</span>
                            @endif
                            @if($r->is_demo)<div><span class="badge sus" style="font-size:10.5px">Prueba</span></div>@endif
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            @if($r->status=='completado')
                                <span class="money">{{ $cur }} {{ number_format($r->final_price ?? $r->totalPrice(),2) }}</span>
                            @else
                                <span class="muted">{{ $cur }} {{ number_format($r->totalPrice(),2) }}</span>
                            @endif
                        </td>
                        <td style="font-size:12.5px">{{ ucfirst($r->payment_method) }}</td>
                        <td style="font-size:12.5px">
                            @php($rcls=['completado'=>'on','cancelado'=>'blk','sin_conductor'=>'sus'][$r->status]??'off')
                            <span class="badge {{ $rcls }}">{{ $r->statusLabel() }}</span>
                            @if($r->status=='cancelado')
                                <div class="muted" style="font-size:11.5px;margin-top:3px">
                                    Canceló: {{ $r->cancelled_by ?: 'sin dato' }}@if($r->cancel_reason) · {{ $r->cancel_reason }}@endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted" style="text-align:center;padding:26px">Este pasajero todavía no pidió ningún viaje.</td></tr>
                @endforelse
                </tbody>
            </table>
            @if($rides->hasPages())
                <div class="pagi" style="padding:10px 16px">{{ $rides->links() }}</div>
            @endif
        </div>
    </div>

    {{-- Acciones --}}
    <div class="grid" style="gap:16px;align-content:start">

        @if($activo)
            <div class="card" style="border:1px solid #FFC107">
                <h3 style="margin-bottom:8px">Viaje en curso</h3>
                <div style="font-size:12.5px;line-height:1.6">
                    <b>{{ $activo->code }}</b> · {{ $activo->statusLabel() }}<br>
                    <span class="muted">Hacia</span> {{ $activo->dest_address ?: 'punto en el mapa' }}<br>
                    <span class="muted">Conductor</span> {{ $activo->driver?->full_name ?: 'sin asignar todavía' }}
                </div>
            </div>
        @endif

        {{-- Estado de cuenta --}}
        <div class="card">
            <h3 style="margin-bottom:10px">Estado de la cuenta</h3>
            <form method="POST" action="{{ route('admin.passengers.status',$passenger) }}" style="display:flex;gap:8px">
                @csrf
                <select class="input" name="account_status">
                    <option value="activo" @selected($passenger->account_status=='activo')>Activo</option>
                    <option value="suspendido" @selected($passenger->account_status=='suspendido')>Suspendido</option>
                    <option value="bloqueado" @selected($passenger->account_status=='bloqueado')>Bloqueado</option>
                </select>
                <button class="btn ghost">Aplicar</button>
            </form>
            <div class="muted" style="font-size:12px;margin-top:8px;line-height:1.55">
                Suspendido o bloqueado deja de poder pedir taxis <b>al instante</b>: aunque tenga la
                app abierta, en el siguiente toque la app lo saca y le muestra el motivo.
                Sus viajes anteriores se conservan.
            </div>
        </div>

        {{-- Clave --}}
        <div class="card">
            <h3 style="margin-bottom:10px">Restablecer clave</h3>
            <div class="muted" style="font-size:12.5px;margin-bottom:10px;line-height:1.5">
                El pasajero no tiene forma de recuperar su clave solo. Si te llama porque la olvidó,
                pónsela aquí y pásasela.
            </div>
            <form method="POST" action="{{ route('admin.passengers.password',$passenger) }}">
                @csrf
                <div class="field"><input class="input" type="text" name="password" placeholder="Clave nueva (mín. 4)" required></div>
                <button class="btn ghost" style="width:100%">Actualizar clave</button>
            </form>
        </div>
    </div>
</div>
@endsection
