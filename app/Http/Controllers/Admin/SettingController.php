<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function edit()
    {
        $settings = [
            'app_name'         => Setting::get('app_name', 'MajesGo'),
            'app_slogan'       => Setting::get('app_slogan', 'Tu taxi en un toque.'),
            'city'             => Setting::get('city', ''),
            'currency'         => Setting::get('currency', 'S/'),
            'commission_value' => Setting::get('commission_value', '0.50'),
            'saldo_tiers'      => Setting::get('saldo_tiers', '20,50,100'),
            'yape_number'      => Setting::get('yape_number', ''),
            'yape_holder'      => Setting::get('yape_holder', ''),
            'min_saldo_alert'  => Setting::get('min_saldo_alert', '5.00'),
        ];

        return view('admin.settings.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'app_name'         => ['required', 'string', 'max:60'],
            'app_slogan'       => ['nullable', 'string', 'max:120'],
            'city'             => ['nullable', 'string', 'max:120'],
            'currency'         => ['required', 'string', 'max:5'],
            'commission_value' => ['required', 'numeric', 'min:0', 'max:100'],
            'saldo_tiers'      => ['required', 'string', 'max:60'],
            'yape_number'      => ['nullable', 'string', 'max:20'],
            'yape_holder'      => ['nullable', 'string', 'max:120'],
            'min_saldo_alert'  => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($data as $k => $v) {
            Setting::put($k, $v);
        }

        return back()->with('ok', 'Configuración guardada.');
    }
}
