@extends('admin.layout')
@section('title','Zonas locales')

@section('content')

<div class="between" style="margin-bottom:16px">
    <div class="muted" style="font-size:13px;max-width:640px">
        Zonas y referencias propias de MajesGo (ej. "El Pionero", "Villa El Pedregal"). Cuando el pasajero
        pone el pin dentro de una zona o la busca por su nombre, la app la reconoce aunque no exista en Google.
    </div>
    <a href="{{ route('admin.places.create') }}" class="btn">＋ Nueva zona</a>
</div>

<div class="card" style="padding:0">
    <table>
        <thead><tr><th>Zona</th><th style="text-align:center">Tipo</th><th>Otros nombres</th><th style="text-align:center">Radio</th><th style="text-align:center">Estado</th><th></th></tr></thead>
        <tbody>
        @forelse($places as $p)
            <tr>
                <td>
                    <div style="font-weight:600">{{ $p->name }}</div>
                    <div class="muted" style="font-size:12px">{{ number_format($p->lat,5) }}, {{ number_format($p->lng,5) }}</div>
                </td>
                <td style="text-align:center">
                    @if($p->is_primary)<span class="badge on"><span class="dot"></span>Principal</span>
                    @else<span class="badge off">Secundaria</span>@endif
                </td>
                <td class="muted" style="font-size:13px">{{ $p->aliases ?: '—' }}</td>
                <td style="text-align:center">{{ $p->radius_m }} m</td>
                <td style="text-align:center">
                    @if($p->active)<span class="badge on"><span class="dot"></span>Activa</span>
                    @else<span class="badge off">Inactiva</span>@endif
                </td>
                <td style="text-align:right;white-space:nowrap">
                    <a href="{{ route('admin.places.edit',$p) }}" class="btn ghost sm">Editar</a>
                    <form method="POST" action="{{ route('admin.places.destroy',$p) }}" style="display:inline" onsubmit="return confirm('¿Eliminar la zona &quot;{{ $p->name }}&quot;?')">
                        @csrf @method('DELETE')
                        <button class="btn danger sm">Eliminar</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted" style="text-align:center;padding:34px">Aún no hay zonas. <a href="{{ route('admin.places.create') }}" style="color:var(--verde-d);font-weight:600">Agregar la primera →</a></td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@endsection
