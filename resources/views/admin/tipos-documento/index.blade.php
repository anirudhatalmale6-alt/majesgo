@extends('admin.layout')
@section('title','Documentos que se exigen')
@section('content')

<div class="card" style="margin-bottom:16px">
    <div style="font-weight:700;margin-bottom:6px">Qué papeles le pedimos a un conductor</div>
    <div class="muted">
        Esta lista es la que ve el conductor en su app. Si la municipalidad cambia los
        requisitos, se edita acá y los conductores lo ven al día siguiente, sin esperar una
        actualización del sistema.
    </div>
</div>

<div class="card" style="padding:0;overflow-x:auto;margin-bottom:16px">
    <table class="table">
        <thead>
        <tr>
            <th>Documento</th>
            <th>Obligatorio</th>
            <th>Vence</th>
            <th>Nº</th>
            <th>Lo tienen</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach($tipos as $t)
            <tr style="{{ $t->active ? '' : 'opacity:.5' }}">
                <td>
                    <form method="POST" action="{{ route('admin.doctypes.update', $t) }}" id="f{{ $t->id }}">
                        @csrf
                        <input class="input" name="name" value="{{ $t->name }}" required minlength="3" maxlength="80" style="font-weight:700">
                        <input class="input" name="help" value="{{ $t->help }}" maxlength="120"
                               placeholder="Ayuda para el conductor (opcional)" style="margin-top:6px;font-size:13px">
                    </form>
                    @unless($t->active)<div class="muted" style="margin-top:6px">Desactivado</div>@endunless
                </td>
                <td><input type="checkbox" name="required" value="1" form="f{{ $t->id }}" @checked($t->required)></td>
                <td><input type="checkbox" name="has_expiry" value="1" form="f{{ $t->id }}" @checked($t->has_expiry)></td>
                <td><input type="checkbox" name="needs_number" value="1" form="f{{ $t->id }}" @checked($t->needs_number)></td>
                <td>{{ $aprobados[$t->id] ?? 0 }}</td>
                <td style="text-align:right;white-space:nowrap">
                    <button class="btn" form="f{{ $t->id }}">Guardar</button>
                    <form method="POST" action="{{ route('admin.doctypes.toggle', $t) }}" style="display:inline">
                        @csrf
                        <button class="btn ghost">{{ $t->active ? 'Desactivar' : 'Reactivar' }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="card">
    <div style="font-weight:700;margin-bottom:10px">Agregar un documento</div>
    <form method="POST" action="{{ route('admin.doctypes.store') }}">
        @csrf
        <div class="grid" style="grid-template-columns:1fr 1fr;gap:10px">
            <div>
                <label>Nombre</label>
                <input class="input" name="name" required minlength="3" maxlength="80" placeholder="Certificado de salud">
            </div>
            <div>
                <label>Ayuda para el conductor</label>
                <input class="input" name="help" maxlength="120" placeholder="Emitido en los últimos 12 meses">
            </div>
        </div>
        <div style="display:flex;gap:18px;align-items:center;margin-top:12px;flex-wrap:wrap">
            <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="required" value="1" checked> Obligatorio</label>
            <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="has_expiry" value="1"> Tiene vencimiento</label>
            <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="needs_number" value="1"> Lleva número</label>
            <button class="btn">Agregar</button>
        </div>
    </form>
    <div class="muted" style="margin-top:12px">
        Un documento que ya tiene conductores aprobados no se borra: se desactiva. Así deja de
        pedirse pero se conserva quién presentó qué y quién lo revisó.
    </div>
</div>

@endsection
