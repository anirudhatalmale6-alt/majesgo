@extends('admin.layout')
@section('title','Fotos de conductores')
@section('content')

<div class="between" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:8px">
        <select class="input" name="estado" style="width:230px" onchange="this.form.submit()">
            <option value="pendiente" @selected($status=='pendiente')>Pendientes de aprobación</option>
            <option value="aprobado" @selected($status=='aprobado')>Aprobadas</option>
            <option value="rechazado" @selected($status=='rechazado')>Rechazadas</option>
            <option value="todas" @selected($status=='todas')>Todas</option>
        </select>
    </form>
    <div class="muted">Ninguna foto se le muestra al pasajero hasta que la apruebes aquí.</div>
</div>

@if($status=='pendiente' && $pendingCount==0)
    <div class="card" style="text-align:center;padding:40px">
        <div style="font-size:34px;margin-bottom:8px">✓</div>
        <div style="font-weight:700;font-size:17px">No hay fotos esperando revisión</div>
        <div class="muted" style="margin-top:6px">Cuando un conductor suba su foto de perfil o la de su vehículo, aparecerá aquí.</div>
    </div>
@endif

<div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(310px,1fr));gap:16px;align-items:start">
    @foreach($photos as $p)
        <div class="card" style="padding:0;overflow:hidden">
            @if($p->status=='pendiente')
                <img src="{{ $p->url() }}" alt="{{ $p->typeLabel() }} de {{ $p->driver->full_name ?? '' }}"
                     class="photozoom" data-full="{{ $p->url() }}"
                     style="width:100%;height:230px;object-fit:cover;background:#0b0d10;cursor:zoom-in;display:block">
            @else
                {{-- de las revisadas no se guarda el archivo: queda el registro de quién decidió y por qué --}}
                <div style="height:96px;display:grid;place-items:center;background:#f4f6f9;color:#8b94a0;font-size:13px">
                    {{ $p->status=='aprobado' ? 'Publicada — se ve en la ficha del conductor' : 'Archivo descartado' }}
                </div>
            @endif

            <div style="padding:14px 16px 16px">
                <div class="between" style="align-items:flex-start;margin-bottom:8px">
                    <div>
                        <div style="font-weight:700">{{ $p->driver->full_name ?? '—' }}</div>
                        <div class="muted" style="font-size:12px">{{ $p->driver->code ?? '' }} · {{ $p->typeLabel() }}</div>
                    </div>
                    @php($pc=['pendiente'=>'sus','aprobado'=>'on','rechazado'=>'blk'][$p->status]??'off')
                    <span class="badge {{ $pc }}">{{ $p->statusLabel() }}</span>
                </div>

                <div class="muted" style="font-size:12px;margin-bottom:10px">
                    Enviada {{ $p->created_at->format('d/m/Y H:i') }}
                    @if($p->reviewed_at) · revisada por {{ $p->reviewer->name ?? 'la central' }} el {{ $p->reviewed_at->format('d/m H:i') }} @endif
                </div>

                @if($p->reject_reason)
                    <div style="background:#ffe9e9;color:#c0322b;border-radius:10px;padding:9px 12px;font-size:12.5px;margin-bottom:10px">
                        Motivo: {{ $p->reject_reason }}
                    </div>
                @endif

                @if($p->status=='pendiente')
                    <div style="display:flex;gap:8px">
                        <form method="POST" action="{{ route('admin.photos.approve',$p) }}" style="flex:1">@csrf
                            <button class="btn sm" style="width:100%">Aprobar</button>
                        </form>
                        <button class="btn danger sm" style="flex:1" onclick="rejectPhoto({{ $p->id }},'{{ addslashes($p->driver->full_name ?? '') }}')">Rechazar</button>
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>

@if($photos->isEmpty() && !($status=='pendiente' && $pendingCount==0))
    <div class="card" style="text-align:center;padding:34px" class="muted">No hay fotos en este estado.</div>
@endif

<div class="pagi">{{ $photos->links() }}</div>

{{-- Ver la foto en grande antes de decidir --}}
<div id="photobox" style="display:none;position:fixed;inset:0;z-index:90;background:rgba(8,10,14,.86);align-items:center;justify-content:center;padding:30px;cursor:zoom-out">
    <img id="photofull" alt="Foto del conductor" style="max-width:min(760px,92vw);max-height:88vh;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.6)">
</div>

{{-- Rechazo con motivo: el conductor lo lee en su app, así que no puede quedar vacío --}}
<div id="rejbox" style="display:none;position:fixed;inset:0;z-index:95;background:rgba(8,10,14,.7);align-items:center;justify-content:center;padding:24px">
    <form method="POST" id="rejform" style="background:#fff;border-radius:16px;padding:22px;width:100%;max-width:430px">
        @csrf
        <h3 style="margin:0 0 6px">Rechazar foto</h3>
        <div class="muted" style="font-size:13px;margin-bottom:14px" id="rejwho"></div>
        <div class="field">
            <label>Motivo (lo verá el conductor)</label>
            <input class="input" name="reason" id="rejreason" maxlength="160" required placeholder="Ej: Foto borrosa">
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:16px">
            @foreach($reasons as $r)
                <button type="button" class="btn ghost sm rquick" data-r="{{ $r }}" style="width:auto;padding:7px 11px;font-size:12px">{{ $r }}</button>
            @endforeach
        </div>
        <div style="display:flex;gap:9px">
            <button type="button" class="btn ghost" style="flex:1" onclick="closeReject()">Cancelar</button>
            <button class="btn danger" style="flex:1">Rechazar foto</button>
        </div>
    </form>
</div>

<script>
    (function () {
        var box = document.getElementById('photobox'), full = document.getElementById('photofull');
        document.querySelectorAll('.photozoom').forEach(function (t) {
            t.addEventListener('click', function () { full.src = t.dataset.full; box.style.display = 'flex'; });
        });
        box.addEventListener('click', function () { box.style.display = 'none'; full.src = ''; });
        document.querySelectorAll('.rquick').forEach(function (b) {
            b.addEventListener('click', function () { document.getElementById('rejreason').value = b.dataset.r; });
        });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            box.style.display = 'none'; full.src = '';
            closeReject();
        });
    })();

    function rejectPhoto(id, name) {
        var f = document.getElementById('rejform');
        f.action = '{{ url('admin/fotos') }}/' + id + '/rechazar';
        document.getElementById('rejwho').textContent = name ? ('Conductor: ' + name) : '';
        document.getElementById('rejreason').value = '';
        document.getElementById('rejbox').style.display = 'flex';
        document.getElementById('rejreason').focus();
    }

    function closeReject() { document.getElementById('rejbox').style.display = 'none'; }
</script>
@endsection
