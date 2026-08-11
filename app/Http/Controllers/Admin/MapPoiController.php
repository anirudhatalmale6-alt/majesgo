<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MapPoi;
use Illuminate\Http\Request;

class MapPoiController extends Controller
{
    public function index(Request $request)
    {
        $q = MapPoi::query();

        if ($s = trim((string) $request->get('q'))) {
            $q->where('name', 'like', "%{$s}%");
        }
        if ($c = $request->get('cat')) {
            $q->where('category', $c);
        }
        if ($request->get('estado') === 'ocultos') {
            $q->where('active', false);
        } elseif ($request->get('estado') === 'visibles') {
            $q->where('active', true);
        }

        $pois = $q->orderBy('priority')->orderBy('name')->paginate(30)->withQueryString();

        return view('admin.pois.index', [
            'pois'       => $pois,
            'categories' => MapPoi::CATEGORIES,
            'total'      => MapPoi::where('active', true)->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['source']   = 'manual';
        $data['active']   = true;
        $data['priority'] = MapPoi::DEFAULT_PRIORITY[$data['category']] ?? 2;

        MapPoi::create($data);

        return back()->with('ok', "«{$data['name']}» agregado al mapa.");
    }

    public function update(Request $request, MapPoi $poi)
    {
        $data = $this->validated($request);
        $poi->update($data);

        return back()->with('ok', 'Punto de referencia actualizado.');
    }

    /** Mostrar u ocultar en el mapa sin borrar el registro. */
    public function toggle(MapPoi $poi)
    {
        $poi->update(['active' => ! $poi->active]);

        return back()->with('ok', $poi->active
            ? "«{$poi->name}» vuelve a verse en el mapa."
            : "«{$poi->name}» ya no se muestra en el mapa.");
    }

    public function destroy(MapPoi $poi)
    {
        $name = $poi->name;
        $poi->delete();

        return back()->with('ok', "«{$name}» eliminado.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'     => ['required', 'string', 'max:60'],
            'category' => ['required', 'in:' . implode(',', array_keys(MapPoi::CATEGORIES))],
            'lat'      => ['required', 'numeric', 'between:-90,90'],
            'lng'      => ['required', 'numeric', 'between:-180,180'],
        ], [
            'name.required' => 'Ponle el nombre con el que la gente lo conoce.',
            'lat.required'  => 'Falta la latitud.',
            'lng.required'  => 'Falta la longitud.',
        ]);
    }
}
