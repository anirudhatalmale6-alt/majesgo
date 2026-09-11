<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Driver extends Model
{
    // Baja reversible: el conductor sale del panel y de la app, pero sus viajes,
    // recargas y movimientos de saldo se conservan (ver la migración de deleted_at).
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected $casts = [
        'saldo'          => 'decimal:2',
        'rating'         => 'decimal:2',
        'last_active_at' => 'datetime',
        'app_seen_at'    => 'datetime',
        // sin el cast, docs_due_at llega como texto y `->isFuture()` revienta justo en el
        // punto donde se decide si alguien puede o no trabajar
        'docs_due_at'    => 'datetime',
        'approved_at'    => 'datetime',
        'self_registered'=> 'boolean',
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

    /** Documentos enviados a la central (DNI, licencia, SOAT…). */
    public function documents()
    {
        return $this->hasMany(DriverDocument::class)->latest('id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /* ---- Helpers de negocio ---- */

    /**
     * Siguiente código MG-####.
     *
     * Vive en el modelo y no en el panel porque ahora hay DOS puertas de alta: la central
     * y el registro desde la app. Dos generadores distintos terminan dando dos códigos
     * distintos para la misma serie.
     *
     * withTrashed: un MG-0007 dado de baja sigue ocupando su código.
     * ⚠ Dos altas simultáneas pueden calcular el mismo número; el índice único de `code`
     * es el que lo impide de verdad, y quien inserta reintenta.
     */
    public static function makeCode(): string
    {
        $max = self::withTrashed()->pluck('code')
            ->filter(fn ($c) => preg_match('/^MG-\d+$/', (string) $c))
            ->map(fn ($c) => (int) \Illuminate\Support\Str::afterLast($c, '-'))
            ->max() ?? 0;

        return 'MG-'.str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }

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
            && ! \App\Services\DriverPhotos::missing($this)
            // El alta con documentos pasa por acá y por ningún otro lado: es el único punto
            // que atraviesan tanto conectarse (connect) como entrar al despacho
            // (Dispatch::eligibleDrivers). Poner el control en la pantalla no serviría.
            && ! \App\Services\DriverDocuments::blocking($this);
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

    /**
     * Corrige el saldo y anota la diferencia como un movimiento de tipo «ajuste».
     *
     * $mode 'fijar'     → el saldo termina valiendo exactamente $value
     * $mode 'descontar' → al saldo se le restan $value
     *
     * Todo se resuelve DENTRO del bloqueo de la fila, con el saldo de ese instante y
     * no con el que el administrador tenía en pantalla: si el conductor cobró una
     * comisión entre que abrió la ficha y pulsó el botón, «dejarlo en 20» lo deja en
     * 20 y «descontar 10» descuenta 10 de verdad. Nunca reescribe los movimientos
     * anteriores — el error de tipeo queda en el historial y la corrección también.
     *
     * Devuelve null si no había nada que corregir.
     * Lanza SaldoNegativo si la corrección dejaría la cuenta debajo de cero.
     */
    public function adjustSaldoTo(float $value, string $mode = 'fijar', string $description = null, int $userId = null): ?SaldoMovement
    {
        return DB::transaction(function () use ($value, $mode, $description, $userId) {
            $fresh  = self::whereKey($this->id)->lockForUpdate()->first();
            $actual = round((float) $fresh->saldo, 2);
            $target = $mode === 'descontar' ? round($actual - $value, 2) : round($value, 2);

            if ($target < 0) {
                throw new \App\Exceptions\SaldoNegativo($actual, $target);
            }

            $delta = round($target - $actual, 2);

            if (abs($delta) < 0.005) {
                $this->saldo = $fresh->saldo;
                return null;
            }

            $movement = $fresh->movements()->create([
                'type'          => 'ajuste',
                'amount'        => $delta,
                'balance_after' => $target,
                'description'   => $description,
                'ref_type'      => 'manual',
                'created_by'    => $userId,
            ]);

            $fresh->update(['saldo' => $target]);
            $this->saldo = $target;

            return $movement;
        });
    }

    /**
     * ¿Tiene algo que perder si se borra de verdad?
     *
     * Cuenta como historial haber trabajado (viajes, comisiones cobradas) o haber pagado
     * (recargas). NO cuenta el «Saldo inicial» que la central le carga al crear la cuenta:
     * ese ajuste lo lleva todo conductor nuevo desde el primer segundo, y si lo tomáramos
     * como historial jamás se podría borrar del todo una cuenta creada por error.
     */
    public function hasHistory(): bool
    {
        return $this->rides()->exists()
            || $this->recharges()->exists()
            || $this->movements()->whereIn('type', ['comision', 'recarga'])->exists();
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
