@extends('admin.layout')
@section('title','Recargas')
@section('content')
@php($cur = \App\Models\Setting::get('currency','S/'))

<div class="between" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:8px">
        <select class="input" name="estado" style="width:170px" onchange="this.form.submit()">
            <option value="">Todas</option>
            <option value="pendiente" @selected(request('estado')=='pendiente')>Pendientes</option>
            <option value="aprobado" @selected(request('estado')=='aprobado')>Aprobadas</option>
            <option value="rechazado" @selected(request('estado')=='rechazado')>Rechazadas</option>
        </select>
    </form>
    <div class="muted">Las recargas por Yape/Plin/transferencia las validas aquí; el saldo se acredita solo al aprobar.</div>
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Conductor</th><th>Método</th><th>Comprobante</th><th>Referencia</th><th style="text-align:right">Monto</th><th>Estado</th><th>Fecha</th><th style="text-align:right">Acción</th></tr></thead>
        <tbody>
        @forelse($recharges as $r)
            <tr>
                <td><div style="font-weight:600">{{ $r->driver->full_name ?? '—' }}</div><div class="muted" style="font-size:12px">{{ $r->driver->code ?? '' }}</div></td>
                <td>{{ $r->methodLabel() }}</td>
                <td>
                    @if($r->receiptUrl())
                        <img src="{{ $r->receiptUrl() }}" alt="Comprobante de {{ $r->driver->full_name ?? '' }}" class="vouchthumb"
                             data-full="{{ $r->receiptUrl() }}"
                             style="width:56px;height:56px;object-fit:cover;border-radius:9px;border:1px solid var(--line);cursor:zoom-in;display:block">
                    @else
                        <span class="muted" style="font-size:12px">Sin comprobante</span>
                    @endif
                </td>
                <td class="muted">{{ $r->reference ?: '—' }}</td>
                <td style="text-align:right"><span class="money">{{ $cur }} {{ number_format($r->amount,2) }}</span></td>
                <td>
                    @php($rc=['pendiente'=>'sus','aprobado'=>'on','rechazado'=>'blk'][$r->status]??'off')
                    <span class="badge {{ $rc }}">{{ $r->statusLabel() }}</span>
                </td>
                <td class="muted" style="font-size:12.5px">{{ $r->created_at->format('d/m/Y H:i') }}</td>
                <td style="text-align:right">
                    @if($r->status=='pendiente')
                        <form method="POST" action="{{ route('admin.recharges.approve',$r) }}" style="display:inline">@csrf<button class="btn sm">Aprobar</button></form>
                        <form method="POST" action="{{ route('admin.recharges.reject',$r) }}" style="display:inline">@csrf<button class="btn danger sm">Rechazar</button></form>
                    @else
                        <span class="muted" style="font-size:12px">{{ $r->validator->name ?? '' }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted" style="text-align:center;padding:30px">No hay recargas registradas.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="pagi">{{ $recharges->links() }}</div>

{{-- Ver el comprobante en grande: hay que poder leer el monto y el número de operación antes de aprobar --}}
<div id="vouchbox" style="display:none;position:fixed;inset:0;z-index:90;background:rgba(8,10,14,.86);align-items:center;justify-content:center;padding:30px;cursor:zoom-out">
    <img id="vouchfull" alt="Comprobante" style="max-width:min(680px,92vw);max-height:88vh;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.6)">
</div>
<script>
    (function () {
        var box = document.getElementById('vouchbox'), full = document.getElementById('vouchfull');
        document.querySelectorAll('.vouchthumb').forEach(function (t) {
            t.addEventListener('click', function () { full.src = t.dataset.full; box.style.display = 'flex'; });
        });
        box.addEventListener('click', function () { box.style.display = 'none'; full.src = ''; });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { box.style.display = 'none'; full.src = ''; } });
    })();
</script>
@endsection
