<?php

namespace App\Services;

use App\Models\FcmToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío de notificaciones push NATIVAS (Firebase Cloud Messaging HTTP v1),
 * para las apps nativas (Capacitor) de Play Store. A prueba de fallos: si no hay
 * credenciales o algo falla, no rompe el flujo del viaje (solo registra).
 * Los tokens inválidos (404 / UNREGISTERED) se limpian solos.
 *
 * No requiere el SDK de Firebase: firma el JWT del service account con openssl
 * y obtiene un access token OAuth2 (cacheado ~55 min).
 */
class FcmSender
{
    /**
     * Primera versión de la app del conductor que lleva el timbre propio dentro del apk
     * (res/raw/nuevo_viaje.mp3). Por debajo de esto el celular NO tiene el archivo, y un
     * canal que apunte a un sonido inexistente sale mudo: a esos se les manda el canal
     * de siempre, con el sonido del sistema.
     */
    public const BUILD_TIMBRE_PROPIO = 3;

    /** Guarda/actualiza el token FCM del dispositivo de un conductor o pasajero. */
    public static function store(string $ownerType, int $ownerId, string $token, string $platform = 'android', int $appBuild = 0): bool
    {
        if ($token === '') {
            return false;
        }
        FcmToken::updateOrCreate(
            ['token' => $token],
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'platform' => $platform, 'app_build' => $appBuild]
        );

        return true;
    }

    public static function toOwner(string $ownerType, int $ownerId, array $payload): void
    {
        self::send(
            FcmToken::where('owner_type', $ownerType)->where('owner_id', $ownerId)->get(['token', 'app_build'])->all(),
            $payload,
            $ownerType
        );
    }

    /** @param array<int> $ownerIds */
    public static function toOwners(string $ownerType, array $ownerIds, array $payload): void
    {
        if (! $ownerIds) {
            return;
        }
        self::send(
            FcmToken::where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->get(['token', 'app_build'])->all(),
            $payload,
            $ownerType
        );
    }

    /**
     * @param array<FcmToken|string> $rows  payload: ['title'=>, 'body'=>, 'url'=>]
     *
     * Acepta modelos (con app_build) o strings sueltos; un string se trata como versión
     * antigua, que es el supuesto seguro: suena con el sonido del sistema.
     */
    private static function send(array $rows, array $payload, string $ownerType = 'driver'): void
    {
        $builds = [];
        foreach ($rows as $r) {
            $t = is_string($r) ? $r : (string) $r->token;
            if ($t === '') {
                continue;
            }
            $b = is_string($r) ? 0 : (int) $r->app_build;
            // Si el mismo token apareciera dos veces, quedarse con la versión más alta.
            $builds[$t] = max($builds[$t] ?? 0, $b);
        }
        $tokens = array_keys($builds);
        if (! $tokens) {
            return;
        }

        $projectId = self::projectId();
        $access = self::accessToken();
        if (! $projectId || ! $access) {
            return;
        }

        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $ok = 0; $gone = 0; $failed = 0;

        /*
         * 'tag' es la pieza clave del aviso de viaje: dos mensajes con el MISMO tag no se
         * apilan, el segundo REEMPLAZA al primero. Así los recordatorios no llenan la barra
         * de notificaciones, y sobre todo: cuando otro conductor se lleva la carrera podemos
         * mandar un aviso silencioso con el mismo tag que borra el que estaba sonando.
         */
        $tag = (string) ($payload['tag'] ?? '');
        $silent = (bool) ($payload['silent'] ?? false);

        // Un aviso de viaje que llega tarde es peor que no llegar: la carrera ya no existe.
        // Con TTL corto, Firebase lo descarta en vez de guardarlo para cuando el celular vuelva.
        $ttl = (int) ($payload['ttl'] ?? 45);

        /*
         * El bloque android depende de la VERSIÓN instalada en ese celular: el timbre propio
         * vive dentro del apk, así que sólo se pide en los que ya lo tienen. Durante una
         * actualización de Play conviven las dos versiones y cada una recibe lo suyo.
         */
        // El mp3 va sólo en el apk del CONDUCTOR: pedirlo en el del pasajero lo dejaría mudo.
        $llevaTimbre = $ownerType === 'driver';

        $bloque = function (int $build) use ($silent, $ttl, $tag, $llevaTimbre): array {
            $propio = ! $silent && $llevaTimbre && $build >= self::BUILD_TIMBRE_PROPIO;
            $android = [
                'priority'     => $silent ? 'NORMAL' : 'HIGH',
                'ttl'          => $ttl.'s',
                'notification' => array_filter([
                    // El canal decide el sonido y si sale como aviso flotante encima de WhatsApp.
                    // ⚠ Sus ajustes quedan CONGELADOS al crearse: para cambiarlos hace falta un
                    // canal con id nuevo (lo crea native.js, sin recompilar la app).
                    'channel_id'          => $silent ? 'majesgo_avisos' : ($propio ? 'majesgo_viajes_v2' : 'majesgo_viajes'),
                    'sound'               => $silent ? null : ($propio ? 'nuevo_viaje' : 'default'),
                    'notification_priority' => $silent ? 'PRIORITY_MIN' : 'PRIORITY_MAX',
                    'default_vibrate_timings' => ! $silent,
                    'visibility'          => 'PUBLIC', // se ve con la pantalla bloqueada
                    'tag'                 => $tag ?: null,
                ], fn ($v) => $v !== null),
            ];
            if ($tag !== '') {
                $android['collapse_key'] = $tag;
            }

            return $android;
        };

        foreach ($tokens as $token) {
            $android = $bloque($builds[$token] ?? 0);
            $message = [
                'message' => [
                    'token'        => $token,
                    'notification' => [
                        'title' => (string) ($payload['title'] ?? 'MajesGo'),
                        'body'  => (string) ($payload['body'] ?? ''),
                    ],
                    'data' => [
                        'url' => (string) ($payload['url'] ?? '/'),
                    ],
                    'android' => $android,
                ],
            ];

            try {
                $res = Http::withToken($access)
                    ->timeout(10)
                    ->post($endpoint, $message);

                if ($res->successful()) {
                    $ok++;
                } elseif (in_array($res->status(), [404, 400], true) && str_contains($res->body(), 'UNREGISTERED')) {
                    $gone++;
                    FcmToken::where('token', $token)->delete();
                } elseif ($res->status() === 404) {
                    $gone++;
                    FcmToken::where('token', $token)->delete();
                } else {
                    $failed++;
                    Log::warning('fcm send failed ('.$res->status().'): '.substr($res->body(), 0, 200));
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('fcm send exception: '.$e->getMessage());
            }
        }

        Log::info("fcm: ok=$ok gone=$gone failed=$failed total=".count($tokens));
    }

    private static function projectId(): ?string
    {
        $creds = self::credentials();
        return $creds['project_id'] ?? config('services.fcm.project_id');
    }

    /** Access token OAuth2 (cacheado ~55 min) a partir del service account. */
    private static function accessToken(): ?string
    {
        return Cache::remember('fcm_access_token', 3300, function () {
            $creds = self::credentials();
            if (! $creds || empty($creds['client_email']) || empty($creds['private_key'])) {
                Log::warning('fcm: service account no configurado');
                return null;
            }

            $now = time();
            $claim = [
                'iss'   => $creds['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud'   => $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $now + 3600,
            ];
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];

            $b64 = fn ($d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
            $signingInput = $b64($header).'.'.$b64($claim);

            $signature = '';
            if (! openssl_sign($signingInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256)) {
                Log::warning('fcm: openssl_sign falló');
                return null;
            }
            $jwt = $signingInput.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

            try {
                $res = Http::asForm()->timeout(10)->post(
                    $creds['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                    [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion'  => $jwt,
                    ]
                );
                if ($res->successful()) {
                    return $res->json('access_token');
                }
                Log::warning('fcm token exchange failed: '.substr($res->body(), 0, 200));
            } catch (\Throwable $e) {
                Log::warning('fcm token exchange exception: '.$e->getMessage());
            }
            return null;
        });
    }

    /** Carga y cachea el JSON del service account. */
    private static function credentials(): ?array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache ?: null;
        }
        $path = config('services.fcm.credentials');
        if (! $path || ! is_file($path)) {
            $cache = false;
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        $cache = is_array($json) ? $json : false;
        return $cache ?: null;
    }
}
