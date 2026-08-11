@extends('admin.layout')
@section('title','Puntos de referencia')
@section('content')

<div class="between" style="margin-bottom:16px">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap">
        <input class="input" name="q" value="{{ request('q') }}" placeholder="Buscar por nombre…" style="width:220px">
        <select class="input" name="cat" style="width:180px" onchange="this.form.submit()">
            <option value="">Todas las categorías</option>
            @foreach($categories as $k => $label)
                <option value="{{ $k }}" @selected(request('cat')==$k)>{{ $label }}</option>
            @endforeach
        </select>
        <select class="input" name="estado" style="width:150px" onchange="this.form.submit()">
            <option value="">Todos</option>
            <option value="visibles" @selected(request('estado')=='visibles')>Visibles</option>
            <option value="ocultos" @selected(request('estado')=='ocultos')>Ocultos</option>
        </select>
        <button class="btn ghost sm" style="width:auto">Buscar</button>
    </form>
    <div class="muted">{{ $total }} puntos visibles en el mapa</div>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="muted" style="font-size:12.5px;line-height:1.55">
        Son los íconos que ven el pasajero y el conductor sobre el mapa para ubicarse.
        Los trajimos de OpenStreetMap; puedes ocultar los que no sirvan y
        <b>renombrarlos con el nombre que usa la gente</b> — si todos dicen «el óvalo», que diga «El Óvalo»
        y no su nombre formal. Los que agregues tú aparecen igual que los demás.
    </div>
</div>

<div class="card" style="margin-bottom:16px">
    <h3 style="margin-bottom:12px">Agregar un punto</h3>
    <form method="POST" action="{{ route('admin.pois.store') }}">
        @csrf
        <div class="row">
            <div class="field"><label>Nombre</label><input class="input" name="name" placeholder="El Óvalo" required></div>
            <div class="field"><label>Categoría</label>
                <select class="input" name="category">
                    @foreach($categories as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="field"><label>Latitud</label><input class="input" name="lat" placeholder="-16.3640" required></div>
            <div class="field"><label>Longitud</label><input class="input" name="lng" placeholder="-72.1920" required></div>
        </div>
        <button class="btn" style="width:auto">Agregar al mapa</button>
        <div class="muted" style="font-size:12px;margin-top:7px">
            Las coordenadas las sacas de Google Maps: mantén pulsado el lugar y copia los dos números.
        </div>
    </form>
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Nombre</th><th>Categoría</th><th>Coordenadas</th><th>Origen</th><th>Estado</th><th style="text-align:right">Acciones</th></tr></thead>
        <tbody>
        @forelse($pois as $p)
            <tr>
                <td>
                    <form method="POST" action="{{ route('admin.pois.update',$p) }}" style="display:flex;gap:6px;align-items:center">
                        @csrf @method('PUT')
                        <input class="input" name="name" value="{{ $p->name }}" style="width:210px;padding:7px 9px;font-size:13px" required>
                        <select class="input" name="category" style="width:150px;padding:7px 9px;font-size:13px">
                            @foreach($categories as $k => $label)
                                <option value="{{ $k }}" @selected($p->category==$k)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" name="lat" value="{{ $p->lat }}">
                        <input type="hidden" name="lng" value="{{ $p->lng }}">
                        <button class="btn ghost sm" style="width:auto">Guardar</button>
                    </form>
                </td>
                <td class="muted">{{ $p->categoryLabel() }}</td>
                <td class="muted" style="font-size:12px">{{ number_format($p->lat,5) }}, {{ number_format($p->lng,5) }}</td>
                <td class="muted" style="font-size:12px">{{ $p->source=='osm' ? 'OpenStreetMap' : 'Agregado por ti' }}</td>
                <td>
                    <span class="badge {{ $p->active ? 'on' : 'off' }}">{{ $p->active ? 'Visible' : 'Oculto' }}</span>
                    @if($p->priority==1)<span class="badge sus" style="margin-left:4px">Se ve de lejos</span>@endif
                </td>
                <td style="text-align:right;white-space:nowrap">
                    <form method="POST" action="{{ route('admin.pois.toggle',$p) }}" style="display:inline">@csrf
                        <button class="btn ghost sm" style="width:auto">{{ $p->active ? 'Ocultar' : 'Mostrar' }}</button>
                    </form>
                    <form method="POST" action="{{ route('admin.pois.destroy',$p) }}" style="display:inline"
                          onsubmit="return confirm('¿Eliminar «{{ $p->name }}» del mapa?')">
                        @csrf @method('DELETE')
                        <button class="btn danger sm" style="width:auto">Eliminar</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:30px">No hay puntos con ese filtro.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="pagi">{{ $pois->links() }}</div>
@endsection
