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
    ];

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
