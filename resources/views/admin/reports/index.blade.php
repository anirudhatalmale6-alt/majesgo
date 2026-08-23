@extends('admin.layout')
@section('title','Denuncias')
@section('content')

<div class="between" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:8px">
        <select class="input" name="estado" style="width:230px" onchange="this.form.submit()">
            <option value="pendiente" @selected($status=='pendiente')>Pendientes de revisar</option>
            <option value="revisado" @selected($status=='revisado')>Revisadas</option>
            <option value="todas" @selected($status=='todas')>Todas</option>
        </select>
    </form>
    <div class="muted">Las denuncias las envían los usuarios desde el chat o al terminar el viaje.</div>
</div>

@if($status=='pendiente' && $pendingCount==0)
    <div class="card" style="text-align:center;padding:40px">
        <div style="font-size:34px;margin-bottom:8px">✓</div>
        <div style="font-weight:700;font-size:17px">No hay denuncias esperando revisión</div>
        <div class="muted" style="margin-top:6px">Cuando un pasajero denuncie a un conductor (o al revés), el caso aparecerá aquí.</div>
    </div>
@endif

<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px;align-items:start">
    @foreach($reports as $rep)
        @php
            $reported = $rep->reported();
            $reporter = $rep->reporter();
            $veces    = $history[$rep->reported_type . ':' . $rep->reported_id] ?? 1;
            // el conductor tiene full_name y el pasajero name: la ficha se abre igual desde las dos
            $nombre   = fn ($u) => $u ? ($u->full_name ?? $u->name ?? '—') : 'cuenta eliminada';
        @endphp
        <div class="card">
            <div class="between" style="align-items:flex-start;margin-bottom:10px">
                <div style="min-width:0">
                    <div style="font-weight:700">{{ $rep->reasonLabel() }}</div>
                    <div class="muted" style="font-size:12px">
                        {{ $rep->created_at->format('d/m/Y H:i') }} · viaje {{ $rep->ride->code ?? '—' }}
                    </div>
                </div>
                <span class="badge {{ $rep->status=='pendiente' ? 'sus' : 'on' }}">
                    {{ $rep->status=='pendiente' ? 'Pendiente' : 'Revisada' }}
                </span>
            </div>

            <div style="background:#f6f8fa;border-radius:12px;padding:11px 13px;font-size:13px;margin-bottom:11px">
                <div>
                    <span class="muted">Denuncia:</span>
                    <b>{{ $nombre($reporter) }}</b>
                    <span class="muted">({{ $rep->reporter_type=='driver' ? 'conductor' : 'pasajero' }})</span>
                </div>
                <div style="margin-top:4px">
                    <span class="muted">Denunciado:</span>
                    <b>{{ $nombre($reported) }}</b>
                    <span class="muted">({{ $rep->reported_type=='driver' ? 'conductor' : 'pasajero' }})</span>
                    @if($reported)
                        <a href="{{ $rep->reported_type=='driver'
                            ? route('admin.drivers.show', $rep->reported_id)
                            : route('admin.passengers.show', $rep->reported_id) }}"
                           class="btn ghost sm" style="margin-left:6px;padding:3px 9px;font-size:11.5px">Ver ficha</a>
                    @endif
                </div>
                @if($veces > 1)
                    <div style="margin-top:7px;color:#c0322b;font-weight:600;font-size:12.5px">
                        ⚠ Acumula {{ $veces }} denuncias en total
                    </div>
                @endif
            </div>

            @if($rep->details)
                <div style="border-left:3px solid var(--amarillo);padding:2px 0 2px 11px;font-size:13.5px;margin-bottom:12px">
                    {{ $rep->details }}
                </div>
            @endif

            @if($rep->status=='pendiente')
                <form method="POST" action="{{ route('admin.reports.review',$rep) }}">@csrf
                    <div class="field" style="margin-bottom:9px">
                        <label>Qué se hizo (opcional, queda guardado)</label>
                        <input class="input" name="admin_note" maxlength="500" placeholder="Ej: Llamé al conductor, quedó advertido">
                    </div>
                    <button class="btn" style="width:100%">Marcar como revisada</button>
                </form>
            @else
                <div class="muted" style="font-size:12.5px">
                    Revisada por {{ $rep->reviewer->name ?? 'la central' }}
                    el {{ $rep->reviewed_at?->format('d/m/Y H:i') }}
                    @if($rep->admin_note)
                        <div style="margin-top:6px;color:#1c2430">“{{ $rep->admin_note }}”</div>
                    @endif
                </div>
                <form method="POST" action="{{ route('admin.reports.reopen',$rep) }}" style="margin-top:10px">@csrf
                    <button class="btn ghost sm">Reabrir</button>
                </form>
            @endif
        </div>
    @endforeach
</div>

@if($reports->isEmpty() && !($status=='pendiente' && $pendingCount==0))
    <div class="card muted" style="text-align:center;padding:34px">No hay denuncias en este estado.</div>
@endif

<div class="pagi">{{ $reports->links() }}</div>

@endsection
