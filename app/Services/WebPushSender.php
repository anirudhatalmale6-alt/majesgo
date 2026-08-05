<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Envío de notificaciones push (Web Push + VAPID). A prueba de fallos: si no hay
 * claves configuradas o algo falla, no rompe el flujo del viaje (solo registra).
 * Las suscripciones expiradas (404/410) se limpian solas.
 */
class WebPushSender
{
    /** Guarda/actualiza la suscripción del navegador de un conductor o pasajero. */
    public static function store(string $ownerType, int $ownerId, array $sub): bool
    {
        $endpoint = $sub['endpoint'] ?? null;
        $p256dh   = $sub['keys']['p256dh'] ?? null;
        $auth     = $sub['keys']['auth'] ?? null;
        if (! $endpoint || ! $p256dh || ! $auth) {
            return false;
        }

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'owner_type' => $ownerType,
                'owner_id'   => $ownerId,
                'endpoint'   => $endpoint,
                'public_key' => $p256dh,
                'auth_token' => $auth,
            ]
        );

        return true;
    }

    public static function toOwner(string $ownerType, int $ownerId, array $payload): void
    {
        self::dispatch(
            PushSubscription::where('owner_type', $ownerType)->where('owner_id', $ownerId)->get(),
            $payload
        );
    }

    /** @param array<int> $ownerIds */
    public static function toOwners(string $ownerType, array $ownerIds, array $payload): void
    {
        if (! $ownerIds) {
            return;
        }
        self::dispatch(
            PushSubscription::where('owner_type', $ownerType)->whereIn('owner_id', $ownerIds)->get(),
            $payload
        );
    }

    private static function dispatch($subs, array $payload): void
    {
        $pub  = config('services.webpush.public_key');
        $priv = config('services.webpush.private_key');
        if (! $pub || ! $priv || $subs->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject'    => config('services.webpush.subject'),
                'publicKey'  => $pub,
                'privateKey' => $priv,
            ]]);

            foreach ($subs as $s) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint'  => $s->endpoint,
                        'publicKey' => $s->public_key,
                        'authToken' => $s->auth_token,
                    ]),
                    json_encode($payload)
                );
            }

            $ok = 0; $expired = 0; $failed = 0;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $ok++;
                } elseif ($report->isSubscriptionExpired()) {
                    $expired++;
                    PushSubscription::where('endpoint_hash', hash('sha256', (string) $report->getEndpoint()))->delete();
                } else {
                    $failed++;
                    Log::warning('webpush send failed: '.$report->getReason());
                }
            }
            Log::info("webpush: ok=$ok expired=$expired failed=$failed total=".$subs->count());
        } catch (\Throwable $e) {
            Log::warning('webpush: '.$e->getMessage());
        }
    }
}
