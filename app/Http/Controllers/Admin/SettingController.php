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
            'commission_pct'   => Setting::get('commission_pct', '5'),
            'fare_per_min'     => Setting::get('fare_per_min', '1.00'),
            'fare_min'         => Setting::get('fare_min', '10.00'),
            'saldo_tiers'      => Setting::get('saldo_tiers', '20,50,100'),
            'yape_number'      => Setting::get('yape_number', ''),
            'yape_holder'      => Setting::get('yape_holder', ''),
            'plin_number'      => Setting::get('plin_number', ''),
            'plin_holder'      => Setting::get('plin_holder', ''),
            'bank_name'        => Setting::get('bank_name', ''),
            'bank_account'     => Setting::get('bank_account', ''),
            'bank_cci'         => Setting::get('bank_cci', ''),
            'bank_holder'      => Setting::get('bank_holder', ''),
            'recharge_note'    => Setting::get('recharge_note', ''),
            'min_saldo_alert'  => Setting::get('min_saldo_alert', '5.00'),
            'dispatch_radius_km' => Setting::get('dispatch_radius_km', '3.0'),
            'dispatch_radius_max_km' => Setting::get('dispatch_radius_max_km', '10.0'),
            'approach_enabled' => Setting::get('approach_enabled', '1'),
            'approach_free_km' => Setting::get('approach_free_km', '3'),
            'approach_per_km'  => Setting::get('approach_per_km', '1.00'),
            'approach_max'     => Setting::get('approach_max', '15.00'),
            'counter_offer_enabled' => Setting::get('counter_offer_enabled', '1'),
            'counter_offer_options' => Setting::get('counter_offer_options', '3,5'),
            'demo_enabled'     => Setting::get('demo_enabled', '1'),
            'require_photos'   => Setting::get('require_photos', '1'),
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
            'commission_pct'   => ['required', 'numeric', 'min:0', 'max:50'],
            'fare_per_min'     => ['required', 'numeric', 'min:0.1', 'max:100'],
            'fare_min'         => ['required', 'numeric', 'min:0.5', 'max:1000'],
            'saldo_tiers'      => ['required', 'string', 'max:60'],
            'yape_number'      => ['nullable', 'string', 'max:20'],
            'yape_holder'      => ['nullable', 'string', 'max:120'],
            'plin_number'      => ['nullable', 'string', 'max:20'],
            'plin_holder'      => ['nullable', 'string', 'max:120'],
            'bank_name'        => ['nullable', 'string', 'max:60'],
            'bank_account'     => ['nullable', 'string', 'max:40'],
            'bank_cci'         => ['nullable', 'string', 'max:40'],
            'bank_holder'      => ['nullable', 'string', 'max:120'],
            'recharge_note'    => ['nullable', 'string', 'max:400'],
            'min_saldo_alert'  => ['required', 'numeric', 'min:0'],
            'dispatch_radius_km' => ['required', 'numeric', 'min:0.5', 'max:50'],
            'dispatch_radius_max_km' => ['required', 'numeric', 'min:0.5', 'max:80'],
            'approach_enabled' => ['nullable'],
            'approach_free_km' => ['required', 'numeric', 'min:0', 'max:50'],
            'approach_per_km'  => ['required', 'numeric', 'min:0', 'max:50'],
            'approach_max'     => ['required', 'numeric', 'min:0', 'max:200'],
            'counter_offer_enabled' => ['nullable'],
            // lista de importes "3,5": Fare::counterOptions() la limpia y limita a 4
            'counter_offer_options' => ['nullable', 'string', 'max:40', 'regex:/^[0-9.,\s]*$/'],
            'demo_enabled'     => ['nullable'],
            'require_photos'   => ['nullable'],
        ]);

        $data['demo_enabled']   = $request->boolean('demo_enabled') ? '1' : '0';
        $data['require_photos'] = $request->boolean('require_photos') ? '1' : '0';
        $data['approach_enabled'] = $request->boolean('approach_enabled') ? '1' : '0';
        $data['counter_offer_enabled'] = $request->boolean('counter_offer_enabled') ? '1' : '0';

        foreach ($data as $k => $v) {
            // los campos opcionales vacíos llegan como null: se guardan como cadena vacía
            Setting::put($k, $v === null ? '' : (string) $v);
        }

        return back()->with('ok', 'Configuración guardada.');
    }
}
