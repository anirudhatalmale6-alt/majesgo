<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomPlace;
use App\Models\Setting;
use Illuminate\Http\Request;

class PlaceController extends Controller
{
    public function index()
    {
        $places = CustomPlace::orderBy('name')->get();

        return view('admin.places.index', compact('places'));
    }

    public function create()
    {
        $place = new CustomPlace([
            'lat'      => (float) Setting::get('map_center_lat', -16.3627),
            'lng'      => (float) Setting::get('map_center_lng', -72.1908),
            'radius_m' => 300,
            'active'   => true,
        ]);

        return view('admin.places.form', compact('place'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        CustomPlace::create($data);

        return redirect()->route('admin.places.index')->with('ok', 'Zona agregada.');
    }

    public function edit(CustomPlace $place)
    {
        return view('admin.places.form', compact('place'));
    }

    public function update(Request $request, CustomPlace $place)
    {
        $place->update($this->validated($request));

        return redirect()->route('admin.places.index')->with('ok', 'Zona actualizada.');
    }

    public function destroy(CustomPlace $place)
    {
        $name = $place->name;
        $place->delete();

        return redirect()->route('admin.places.index')->with('ok', "Zona \"{$name}\" eliminada.");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'       => ['required', 'string', 'max:120'],
            'aliases'    => ['nullable', 'string', 'max:300'],
            'lat'        => ['required', 'numeric', 'between:-90,90'],
            'lng'        => ['required', 'numeric', 'between:-180,180'],
            'radius_m'   => ['required', 'integer', 'min:50', 'max:5000'],
            'active'     => ['nullable'],
            'is_primary' => ['nullable'],
        ]);
        $data['active'] = $request->boolean('active');
        $data['is_primary'] = $request->boolean('is_primary');

        return $data;
    }
}
