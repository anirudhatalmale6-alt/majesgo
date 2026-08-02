<?php

namespace App\Http\Controllers\Driver;

use App\Http\Controllers\Controller;
use App\Models\Setting;

class PageController extends Controller
{
    public function app()
    {
        return view('driver.app', [
            'city'      => Setting::get('city', 'Majes - El Pedregal'),
            'currency'  => Setting::get('currency', 'S/'),
            'centerLat' => (float) Setting::get('map_center_lat', -16.3627),
            'centerLng' => (float) Setting::get('map_center_lng', -72.1908),
        ]);
    }
}
