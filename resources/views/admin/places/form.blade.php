@extends('admin.layout')
@section('title', $place->exists ? 'Editar zona' : 'Nueva zona')

@section('content')

<form method="POST" action="{{ $place->exists ? route('admin.places.update',$place) : route('admin.places.store') }}">
    @csrf
    @if($place->exists) @method('PUT') @endif

    <div class="grid" style="grid-template-columns:1fr 1.2fr;align-items:start;gap:18px">
        <div class="card">
            <h3 style="margin-bottom:14px">Datos de la zona</h3>
            <div class="field">
                <label>Nombre de la zona</label>
                <input class="input" name="name" value="{{ old('name',$place->name) }}" placeholder="Ej: El Pionero" required>
            </div>
            <div class="field">
                <label>Otros nombres / apodos (opcional)</label>
                <input class="input" name="aliases" value="{{ old('aliases',$place->aliases) }}" placeholder="Ej: Pionero, El Pio, Asoc. Pionero">
                <div class="muted" style="font-size:12px;margin-top:5px">Separados por comas. Sirven para que la búsqueda encuentre la zona por cualquiera de esos nombres.</div>
            </div>
            <div class="field">
                <label>Radio de cobertura (metros)</label>
                <input class="input" type="number" min="50" max="5000" step="10" name="radius_m" id="radius_m" value="{{ old('radius_m',$place->radius_m ?? 300) }}" required>
                <div class="muted" style="font-size:12px;margin-top:5px">Qué tan grande es la zona alrededor del punto. Ej: 300 m para un sector pequeño.</div>
            </div>
            <div class="field">
                <label style="display:flex;align-items:center;gap:9px;cursor:pointer">
                    <input type="checkbox" name="active" value="1" @checked(old('active',$place->active ?? true))> Zona activa (visible en la app)
                </label>
            </div>
            <div style="display:flex;gap:10px;margin-top:8px">
                <button class="btn">{{ $place->exists ? 'Guardar cambios' : 'Agregar zona' }}</button>
                <a href="{{ route('admin.places.index') }}" class="btn ghost">Cancelar</a>
            </div>
        </div>

        <div class="card">
            <h3 style="margin-bottom:8px">Ubicación en el mapa</h3>
            <div class="muted" style="font-size:12.5px;margin-bottom:10px">Toca el mapa (o arrastra el marcador) para poner el punto exacto de la zona. El círculo muestra el radio.</div>
            <div id="pmap" style="height:380px;border-radius:12px;overflow:hidden"></div>
            <input type="hidden" name="lat" id="lat" value="{{ old('lat',$place->lat) }}">
            <input type="hidden" name="lng" id="lng" value="{{ old('lng',$place->lng) }}">
            <div class="muted" style="font-size:12px;margin-top:8px">Punto: <span id="coordlbl">{{ number_format($place->lat,5) }}, {{ number_format($place->lng,5) }}</span></div>
        </div>
    </div>
</form>

@push('scripts')
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
(function(){
    var lat = parseFloat(document.getElementById('lat').value) || -16.3627;
    var lng = parseFloat(document.getElementById('lng').value) || -72.1908;
    var map = L.map('pmap').setView([lat,lng], 15);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {maxZoom:20, subdomains:'abcd'}).addTo(map);
    var marker = L.marker([lat,lng], {draggable:true}).addTo(map);
    var circle = L.circle([lat,lng], {radius: parseInt(document.getElementById('radius_m').value)||300, color:'#00C853', fillColor:'#00C853', fillOpacity:.12}).addTo(map);
    function set(ll){
        document.getElementById('lat').value = ll.lat.toFixed(7);
        document.getElementById('lng').value = ll.lng.toFixed(7);
        document.getElementById('coordlbl').textContent = ll.lat.toFixed(5)+', '+ll.lng.toFixed(5);
        circle.setLatLng(ll);
    }
    map.on('click', function(e){ marker.setLatLng(e.latlng); set(e.latlng); });
    marker.on('dragend', function(){ var ll=marker.getLatLng(); set(ll); });
    document.getElementById('radius_m').addEventListener('input', function(){
        var r = parseInt(this.value)||0; if(r>0) circle.setRadius(r);
    });
    setTimeout(function(){ map.invalidateSize(); }, 120);
})();
</script>
@endpush

@endsection
