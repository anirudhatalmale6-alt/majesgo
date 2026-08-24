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
            'alertSound' => self::alertSoundUrl(),
        ]);
    }

    /**
     * Sonido del aviso de viaje nuevo dentro de la app.
     * Se puede cambiar dejando un archivo en public/audio/nuevo-viaje.mp3, o poniendo otra
     * ruta/URL en el ajuste 'driver_alert_sound'. Si no hay archivo devuelve null y la app
     * usa su tono propio, así nunca se queda sin aviso.
     * El ?v= con la fecha del archivo evita que el celular siga con el sonido viejo en caché.
     */
    public static function alertSoundUrl(): ?string
    {
        $path = trim((string) Setting::get('driver_alert_sound', '')) ?: 'audio/nuevo-viaje.mp3';

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $file = public_path(ltrim($path, '/'));

        return is_file($file) ? asset($path) . '?v=' . filemtime($file) : null;
    }
}
