@extends('admin.layout')
@section('title','Postulaciones de conductores')
@section('content')

<div class="between" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:8px">
        <select class="input" name="estado" style="width:250px" onchange="this.form.submit()">
            <option value="pendientes" @selected($estado=='pendientes')>Esperando revisión</option>
            <option value="todas" @selected($estado=='todas')>Todas las postulaciones</option>
        </select>
    </form>
    <div class="muted">Los conductores se registran desde la app y envían sus documentos.</div>
</div>

@if($drivers->isEmpty())
    <div class="card" style="text-align:center;padding:40px">
        <div style="font-size:34px;margin-bottom:8px">✓</div>
        <div style="font-weight:700;font-size:17px">No hay postulaciones esperando revisión</div>
        <div class="muted" style="margin-top:6px">
            Cuando alguien se registre desde la app y suba sus papeles, aparecerá aquí.
        </div>
    </div>
@else
<div class="card" style="padding:0;overflow-x:auto">
    <table class="table">
        <thead>
        <tr>
            <th>Conductor</th>
            <th>Vehículo</th>
            <th>Estado</th>
            <th>Por revisar</th>
            <th>Se registró</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach($drivers as $d)
            <tr>
                <td>
                    <div style="font-weight:700">{{ $d->full_name }}</div>
                    <div class="muted">{{ $d->code }} · {{ $d->phone }}@if($d->dni) · DNI {{ $d->dni }}@endif</div>
                </td>
                <td>
                    {{ $d->vehicle_make }} {{ $d->vehicle_model }}
                    <div class="muted">{{ $d->vehicle_plate }} · {{ $d->vehicle_color }}</div>
                </td>
                <td>
                    @php $e = $d->onboarding_status; @endphp
                    <span class="chip {{ $e=='enviado' ? 'amber' : ($e=='observado' ? 'red' : ($e=='aprobado' ? 'green' : '')) }}">
                        {{ ['registrado'=>'Registrado, sin enviar','enviado'=>'Esperando revisión','observado'=>'Observado','aprobado'=>'Aprobado'][$e] ?? $e }}
                    </span>
                    @if($d->self_registered)<div class="muted" style="margin-top:4px">desde la app</div>@endif
                </td>
                <td>{{ $d->pendientes_count ?: '—' }}</td>
                <td class="muted">{{ $d->created_at?->diffForHumans() }}</td>
                <td style="text-align:right">
                    <a class="btn" href="{{ route('admin.onboarding.show', $d) }}">Revisar</a>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div style="margin-top:16px">{{ $drivers->links() }}</div>
@endif

@endsection
