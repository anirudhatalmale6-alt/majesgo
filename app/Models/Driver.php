<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Driver extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected $casts = [
        'saldo'          => 'decimal:2',
        'rating'         => 'decimal:2',
        'last_active_at' => 'datetime',
    ];

    public function recharges()
    {
        return $this->hasMany(Recharge::class);
    }

    public function rides()
    {
        return $this->hasMany(Ride::class)->latest('id');
    }

    /** Viaje activo actual del conductor (uno a la vez). */
    public function activeRide(): ?Ride
    {
        return $this->rides()->whereIn('status', Ride::ACTIVE_STATES)->first();
    }

    public function movements()
    {
        return $this->hasMany(SaldoMovement::class)->latest();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Fotos enviadas a la central (rostro y vehículo), con su estado de aprobación. */
    public function photos()
    {
        return $this->hasMany(DriverPhoto::class)->latest('id');
    }

    /* ---- Helpers de negocio ---- */

    public function isBlocked(): bool
    {
        return $this->account_status !== 'activo';
    }

    /**
     * Puede recibir viajes: cuenta activa + saldo suficiente para la comisión mínima
     * + sus fotos (rostro y vehículo) aprobadas por la central.
     *
     * El requisito de fotos también se evalúa aquí, y no solo al conectarse, para que un
     * conductor ya conectado deje de recibir viajes en cuanto la central le rechace una foto.
     */
    public function canReceiveRides(): bool
    {
        return $this->account_status === 'activo'
            && (float) $this->saldo >= \App\Services\Fare::minSaldo()
            && ! \App\Services\DriverPhotos::missing($this);
    }

    /**
     * Registra un movimiento de saldo y actualiza el balance de forma atómica.
     * $amount firmado: positivo = recarga/ajuste+, negativo = comisión.
     */
    public function applyMovement(string $type, float $amount, string $description = null, string $refType = null, int $refId = null, int $userId = null): SaldoMovement
    {
        return DB::transaction(function () use ($type, $amount, $description, $refType, $refId, $userId) {
            $fresh = self::whereKey($this->id)->lockForUpdate()->first();
            $newBalance = round((float) $fresh->saldo + $amount, 2);

            $movement = $fresh->movements()->create([
                'type'          => $type,
                'amount'        => $amount,
                'balance_after' => $newBalance,
                'description'   => $description,
                'ref_type'      => $refType,
                'ref_id'        => $refId,
                'created_by'    => $userId,
            ]);

            $fresh->update(['saldo' => $newBalance]);
            $this->saldo = $newBalance;

            return $movement;
        });
    }

    public function statusLabel(): string
    {
        return [
            'disponible'   => 'Disponible',
            'ocupado'      => 'Ocupado',
            'desconectado' => 'Desconectado',
        ][$this->status] ?? $this->status;
    }

    public function accountLabel(): string
    {
        return [
            'activo'     => 'Activo',
            'suspendido' => 'Suspendido',
            'bloqueado'  => 'Bloqueado',
        ][$this->account_status] ?? $this->account_status;
    }

    public function vehicleSummary(): string
    {
        $parts = array_filter([$this->vehicle_make, $this->vehicle_model]);
        $car   = implode(' ', $parts);
        return trim($car . ($this->vehicle_plate ? " · {$this->vehicle_plate}" : ''));
    }
}
