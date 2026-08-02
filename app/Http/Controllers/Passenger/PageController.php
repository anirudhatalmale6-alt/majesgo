<?php

namespace App\Http\Controllers\Passenger;

use App\Http\Controllers\Controller;
use App\Models\Setting;

class PageController extends Controller
{
    public function app()
    {
        return view('passenger.app', [
            'city'     => Setting::get('city', 'Majes - El Pedregal'),
            'currency' => Setting::get('currency', 'S/'),
            // centro por defecto del mapa: El Pedregal, Majes (Arequipa)
            'centerLat' => (float) Setting::get('map_center_lat', -16.3627),
            'centerLng' => (float) Setting::get('map_center_lng', -72.1908),
            'yapeNumber' => Setting::get('yape_number', ''),
            'yapeHolder' => Setting::get('yape_holder', ''),
        ]);
    }
}
