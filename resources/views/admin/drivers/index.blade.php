@extends('admin.layout')
@section('title','Conductores')

@section('content')
@php($cur = \App\Models\Setting::get('currency','S/'))

<div class="between" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:8px">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Buscar por nombre, teléfono, placa o código…" style="width:320px">
        <select class="input" name="estado" style="width:150px" onchange="this.form.submit()">
            <option value="">Todos los estados</option>
            <option value="activo" @selected(request('estado')=='activo')>Activos</option>
            <option value="suspendido" @selected(request('estado')=='suspendido')>Suspendidos</option>
            <option value="bloqueado" @selected(request('estado')=='bloqueado')>Bloqueados</option>
        </select>
        <button class="btn ghost">Buscar</button>
    </form>
    <a href="{{ route('admin.drivers.create') }}" class="btn">＋ Nuevo conductor</a>
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Conductor</th><th>Vehículo</th><th>Operativo</th><th>Cuenta</th><th style="text-align:right">Saldo</th><th style="text-align:center">Viajes</th><th></th></tr></thead>
        <tbody>
        @forelse($drivers as $d)
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:11px">
                        <div class="avci">{{ strtoupper(substr($d->full_name,0,1)) }}</div>
                        <div><div style="font-weight:600">{{ $d->full_name }}</div><div class="muted" style="font-size:12px">{{ $d->code }} · {{ $d->phone }}</div></div>
                    </div>
                </td>
                <td>{{ $d->vehicleSummary() ?: '—' }}<div class="muted" style="font-size:12px">{{ $d->vehicle_color }}</div></td>
                <td>
                    @php($cls=['disponible'=>'on','ocupado'=>'busy','desconectado'=>'off'][$d->status]??'off')
                    <span class="badge {{ $cls }}"><span class="dot"></span>{{ $d->statusLabel() }}</span>
                </td>
                <td>
                    @php($acls=['activo'=>'on','suspendido'=>'sus','bloqueado'=>'blk'][$d->account_status]??'off')
                    <span class="badge {{ $acls }}">{{ $d->accountLabel() }}</span>
                </td>
                <td style="text-align:right"><span class="money">{{ $cur }} {{ number_format($d->saldo,2) }}</span></td>
                <td style="text-align:center">{{ $d->total_trips }}</td>
                <td style="text-align:right"><a href="{{ route('admin.drivers.show',$d) }}" class="btn ghost sm">Ver</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="muted" style="text-align:center;padding:34px">No se encontraron conductores. <a href="{{ route('admin.drivers.create') }}" style="color:var(--verde-d);font-weight:600">Crear el primero →</a></td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="pagi">{{ $drivers->links() }}</div>
@endsection
