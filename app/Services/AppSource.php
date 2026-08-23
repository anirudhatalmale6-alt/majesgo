<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Registra si la persona está usando la app instalada de Play o el navegador.
 *
 * La app nativa (Capacitor) manda la cabecera X-MajesGo-App en cada llamada. El navegador
 * no la manda nunca, así que la ausencia también es un dato fiable.
 */
class AppSource
{
    /** Cada cuánto se vuelve a escribir si nada cambió. Las apps consultan cada 3 s. */
    private const THROTTLE_MINUTES = 5;

    public static function fromRequest(Request $request): string
    {
        return $request->header('X-MajesGo-App') === 'native' ? 'play' : 'web';
    }

    /**
     * Anota el origen en el pasajero o el conductor.
     *
     * Escribe siempre que el origen CAMBIE (es el momento interesante: acaba de instalar
     * la app de Play), y si no cambió, como mucho una vez cada 5 minutos. Sin ese freno
     * serían dos escrituras por segundo por persona conectada, solo para guardar lo mismo.
     */
    public static function touch(Model $user, Request $request): void
    {
        $source = self::fromRequest($request);

        $cambio = $user->app_source !== $source;
        $viejo  = ! $user->app_seen_at || $user->app_seen_at->lt(now()->subMinutes(self::THROTTLE_MINUTES));

        if (! $cambio && ! $viejo) {
            return;
        }

        // sin tocar updated_at: este latido no es un cambio del conductor ni del pasajero,
        // y si lo moviera, "modificado por última vez" en el panel diría cualquier cosa
        $user->timestamps = false;
        $user->forceFill(['app_source' => $source, 'app_seen_at' => now()])->saveQuietly();
        $user->timestamps = true;
    }

    public static function label(?string $source): string
    {
        return ['play' => 'App de Play', 'web' => 'Navegador'][$source] ?? 'Sin actividad';
    }
}
