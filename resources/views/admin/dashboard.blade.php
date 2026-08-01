@extends('admin.layout')
@section('title','Inicio')

@section('content')
@php($cur = \App\Models\Setting::get('currency','S/'))
@php($com = \App\Models\Setting::get('commission_value','0.50'))

<div class="grid stats" style="margin-bottom:18px">
    <div class="stat">
        <div class="ic g"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 17h14M6 17l1.5-5A2 2 0 0 1 9.4 10.6h5.2a2 2 0 0 1 1.9 1.4L18 17"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/></svg></div>
        <div class="v">{{ $stats['drivers_total'] }}</div>
        <div class="l">Conductores registrados</div>
    </div>
    <div class="stat">
        <div class="ic y"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
        <div class="v">{{ $stats['drivers_online'] }}</div>
        <div class="l">Conectados ahora ({{ $stats['drivers_available'] }} disponibles)</div>
    </div>
    <div class="stat">
        <div class="ic b"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg></div>
        <div class="v">{{ $cur }} {{ number_format($stats['saldo_total'],2) }}</div>
        <div class="l">Saldo total de conductores</div>
    </div>
    <div class="stat">
        <div class="ic r"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></div>
        <div class="v">{{ $stats['recharges_pending'] }}</div>
        <div class="l">Recargas por validar</div>
    </div>
</div>

<div class="grid" style="grid-template-columns:1.6fr 1fr">
    <div class="card">
        <div class="between" style="margin-bottom:6px">
            <h3>Conductores recientes</h3>
            <a href="{{ route('admin.drivers.index') }}" class="btn ghost sm">Ver todos</a>
        </div>
        <table>
            <thead><tr><th>Conductor</th><th>Vehículo</th><th>Estado</th><th style="text-align:right">Saldo</th></tr></thead>
            <tbody>
            @forelse($recentDrivers as $d)
                <tr onclick="location='{{ route('admin.drivers.show',$d) }}'" style="cursor:pointer">
                    <td>
                        <div style="display:flex;align-items:center;gap:10px">
                            <div class="avci">{{ strtoupper(substr($d->full_name,0,1)) }}</div>
                            <div><div style="font-weight:600">{{ $d->full_name }}</div><div class="muted" style="font-size:12px">{{ $d->code }} · {{ $d->phone }}</div></div>
                        </div>
                    </td>
                    <td>{{ $d->vehicleSummary() ?: '—' }}</td>
                    <td>
                        @php($cls=['disponible'=>'on','ocupado'=>'busy','desconectado'=>'off'][$d->status]??'off')
                        <span class="badge {{ $cls }}"><span class="dot"></span>{{ $d->statusLabel() }}</span>
                    </td>
                    <td style="text-align:right"><span class="money">{{ $cur }} {{ number_format($d->saldo,2) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted" style="text-align:center;padding:24px">Aún no hay conductores.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="grid" style="gap:16px;align-content:start">
        <div class="card" style="background:linear-gradient(135deg,#0d0d0d,#15251a);color:#fff;border:0">
            <div class="muted" style="color:#9aa6b2;font-size:12.5px">Comisión por carrera</div>
            <div style="font-size:30px;font-weight:800;margin:4px 0">{{ $cur }} {{ number_format((float)$com,2) }}</div>
            <div style="color:#aeb7c2;font-size:12.5px">Se descuenta del saldo del conductor por cada viaje completado. Configurable.</div>
            <a href="{{ route('admin.settings.edit') }}" class="btn amber sm" style="margin-top:12px">Cambiar comisión</a>
        </div>

        <div class="card">
            <div class="between"><h3>Recargas pendientes</h3>@if($stats['recharges_pending'])<span class="badge sus">{{ $stats['recharges_pending'] }}</span>@endif</div>
            @forelse($pendingRecharges as $r)
                <div class="between" style="padding:9px 0;border-bottom:1px solid #f0f2f5">
                    <div><div style="font-weight:600">{{ $r->driver->full_name ?? '—' }}</div><div class="muted" style="font-size:12px">{{ $r->methodLabel() }} · {{ $r->created_at->diffForHumans() }}</div></div>
                    <div class="money">{{ $cur }} {{ number_format($r->amount,2) }}</div>
                </div>
            @empty
                <div class="muted" style="padding:10px 0;font-size:13px">No hay recargas pendientes. 👍</div>
            @endforelse
            <a href="{{ route('admin.recharges.index') }}" class="btn ghost sm" style="margin-top:12px">Gestionar recargas</a>
        </div>

        @if($needRecharge)
        <div class="card" style="border-color:#ffd9b0;background:#fff8ef">
            <div style="font-weight:700;color:#c77700">⚠ {{ $needRecharge }} conductor(es) sin saldo suficiente</div>
            <div class="muted" style="font-size:12.5px;margin-top:4px">No reciben viajes hasta recargar (saldo menor a la comisión).</div>
        </div>
        @endif
    </div>
</div>
@endsection
