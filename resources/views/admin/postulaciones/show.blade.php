@extends('admin.layout')
@section('title','Postulación de '.$driver->full_name)
@section('content')

<div class="between" style="margin-bottom:16px">
    <a class="btn ghost" href="{{ route('admin.onboarding.index') }}">‹ Volver a postulaciones</a>
    <div class="muted">{{ $driver->code }} · {{ $driver->phone }}</div>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="between">
        <div>
            <div style="font-weight:800;font-size:18px">{{ $driver->full_name }}</div>
            <div class="muted">
                {{ $driver->vehicle_make }} {{ $driver->vehicle_model }} ·
                {{ $driver->vehicle_plate }} · {{ $driver->vehicle_color }}
                @if($driver->dni) · DNI {{ $driver->dni }} @endif
            </div>
        </div>
        <div style="text-align:right">
            @php $e = $driver->onboarding_status; @endphp
            <span class="chip {{ $e=='aprobado' ? 'green' : ($e=='observado' ? 'red' : 'amber') }}">
                {{ ['registrado'=>'Registrado, sin enviar','enviado'=>'Esperando revisión','observado'=>'Observado','aprobado'=>'Aprobado'][$e] ?? $e }}
            </span>
            @if($driver->docs_due_at)
                <div class="muted" style="margin-top:6px">
                    Plazo hasta {{ $driver->docs_due_at->format('d/m/Y') }}
                </div>
            @endif
        </div>
    </div>

    @if($faltan)
        <div class="muted" style="margin-top:12px">
            Le falta tener vigente: {{ collect($faltan)->pluck('name')->join(', ') }}.
            Mientras tanto no puede conectarse.
        </div>
    @else
        <div class="muted" style="margin-top:12px">Tiene todos los documentos exigidos vigentes.</div>
    @endif
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;align-items:start">
    @foreach($driver->documents->groupBy('document_type_id') as $grupo)
        @php $doc = $grupo->first(); @endphp
        <div class="card">
            <div class="between">
                <div style="font-weight:700">{{ $doc->type->name ?? 'Documento' }}</div>
                <span class="chip {{ $doc->vencido() ? 'red' : ['aprobado'=>'green','rechazado'=>'red','pendiente'=>'amber'][$doc->status] ?? '' }}">
                    {{ $doc->vencido() ? 'Vencido' : ucfirst($doc->status) }}
                </span>
            </div>

            <div class="muted" style="margin-top:6px">
                @if($doc->number) Nº {{ $doc->number }} @endif
                @if($doc->expires_at)
                    · vence {{ $doc->expires_at->format('d/m/Y') }}
                    @if($doc->vencido()) (ya venció) @endif
                @endif
            </div>

            @if($doc->status !== 'rechazado')
                <a class="btn ghost" style="margin-top:10px;display:inline-block"
                   href="{{ route('admin.documents.file', $doc) }}" target="_blank" rel="noopener">
                    Ver el archivo
                </a>
            @else
                <div class="muted" style="margin-top:10px">
                    Rechazado: {{ $doc->reject_reason }}
                    <br>El archivo se borró al rechazarlo.
                </div>
            @endif

            @if($doc->reviewed_at)
                <div class="muted" style="margin-top:8px">
                    Revisado {{ $doc->reviewed_at->format('d/m/Y H:i') }}
                    @if($doc->reviewer) por {{ $doc->reviewer->name }} @endif
                </div>
            @endif

            @if($doc->status === 'pendiente')
                <div style="display:flex;gap:8px;margin-top:12px">
                    <form method="POST" action="{{ route('admin.documents.approve', $doc) }}">
                        @csrf
                        <button class="btn">Aprobar</button>
                    </form>
                    <details>
                        <summary class="btn danger" style="cursor:pointer">Rechazar</summary>
                        <form method="POST" action="{{ route('admin.documents.reject', $doc) }}" style="margin-top:8px">
                            @csrf
                            <input class="input" name="reason" placeholder="Motivo que verá el conductor" required minlength="5" maxlength="200">
                            <button class="btn danger" style="margin-top:8px">Confirmar rechazo</button>
                        </form>
                    </details>
                </div>
            @endif
        </div>
    @endforeach
</div>

<div class="card" style="margin-top:16px">
    <div style="font-weight:700;margin-bottom:6px">Habilitar a mano</div>
    <div class="muted" style="margin-bottom:10px">
        Para casos especiales. Queda registrado que lo habilitaste vos y cuándo. Si le das un
        plazo, puede trabajar mientras termina de regularizar sus papeles.
    </div>
    <form method="POST" action="{{ route('admin.onboarding.approve', $driver) }}" style="display:flex;gap:8px;align-items:center">
        @csrf
        <input class="input" type="number" name="dias_plazo" min="1" max="180" placeholder="Días de plazo (opcional)" style="width:220px">
        <button class="btn">Habilitar a este conductor</button>
    </form>
</div>

@endsection
