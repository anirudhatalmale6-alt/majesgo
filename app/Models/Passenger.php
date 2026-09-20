<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Passenger extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected $casts = [
        'rating'         => 'decimal:2',
        'last_active_at' => 'datetime',
        'app_seen_at'    => 'datetime',
    ];

    /**
     * Cuándo se le vio por última vez de verdad.
     *
     * 🔴 `last_active_at` NO sirve solo para esto: en el pasajero se escribe únicamente al
     * INICIAR SESIÓN, y como la sesión de la app queda abierta meses, se congela en el día
     * que instaló. Un pasajero que viaja a diario figuraba "hace 3 semanas".
     *
     * `app_seen_at` sí es presencia: lo escribe el middleware en cada llamada autenticada
     * (con freno de 5 minutos). Se devuelve la más reciente de las dos para no perder a
     * quien entró antes de que `app_seen_at` existiera.
     */
    public function ultimaActividad(): ?\Illuminate\Support\Carbon
    {
        $v = array_filter([$this->app_seen_at, $this->last_active_at]);
        return $v ? max($v) : null;
    }

    public function rides()
    {
        return $this->hasMany(Ride::class)->latest();
    }

    public function activeRide()
    {
        return $this->hasMany(Ride::class)
            ->whereIn('status', Ride::ACTIVE_STATES)
            ->latest()
            ->first();
    }

    public function isBlocked(): bool
    {
        return $this->account_status !== 'activo';
    }

    public function accountLabel(): string
    {
        return [
            'activo'     => 'Activo',
            'suspendido' => 'Suspendido',
            'bloqueado'  => 'Bloqueado',
        ][$this->account_status] ?? $this->account_status;
    }

    /** Mensaje que ve el pasajero cuando la central le cerró la cuenta. */
    public function blockedMessage(): string
    {
        return $this->account_status === 'bloqueado'
            ? 'Tu cuenta fue bloqueada. Comunícate con MajesGo para más información.'
            : 'Tu cuenta está suspendida. Comunícate con MajesGo para reactivarla.';
    }
}
