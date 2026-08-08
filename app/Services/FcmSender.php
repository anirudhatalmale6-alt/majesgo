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
    /** Guarda/actualiza el token FCM del dispositivo de un conductor o pasajero. */
    public static function store(string $ownerType, int $ownerId, string $token, string $platform = 'android'): bool
    {
        if ($token === '') {
            return false;
        }
        FcmToken::updateOrCreate(
            ['token' => $token],
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'platform' => $platform]
        );

        return true;
    }

    public static function toOwner(string $ownerType, int $ownerId, array $payload): void
    {
        self::send(
            FcmToken::where('owner_type', $ownerType)->where('owner_id', $ownerId)->pluck('token')->all(),
            $payload
        );
    }

    /** @param array<int> $ownerIds */
    public static function toOwners(string $ownerType, array $ownerIds, array $payload): void
    {
        if (! $ownerIds) {
            return;
        }
        self::send(
            FcmToken::where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->pluck('token')->all(),
            $payload
        );
    }

    /** @param array<string> $tokens  payload: ['title'=>, 'body'=>, 'url'=>] */
    private static function send(array $tokens, array $payload): void
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
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

        foreach ($tokens as $token) {
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
                    'android' => [
                        'priority'     => 'HIGH',
                        'notification' => [
                            'sound'        => 'default',
                            'channel_id'   => 'majesgo_viajes',
                            'default_vibrate_timings' => true,
                        ],
                    ],
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
