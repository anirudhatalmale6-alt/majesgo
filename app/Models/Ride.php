<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ride extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'suggested_price'  => 'decimal:2',
        'offered_price'    => 'decimal:2',
        'final_price'      => 'decimal:2',
        'commission'       => 'decimal:2',
        'route_to_pickup'  => 'array',
        'route_trip'       => 'array',
        'excluded_driver_ids' => 'array',
        'is_demo'          => 'boolean',
        'requested_at'     => 'datetime',
        'offered_at'       => 'datetime',
        'accepted_at'      => 'datetime',
        'arrived_at'       => 'datetime',
        'started_at'       => 'datetime',
        'completed_at'     => 'datetime',
        'cancelled_at'     => 'datetime',
    ];

    /** Estados en los que el viaje sigue "vivo" (uno activo por pasajero). */
    public const ACTIVE_STATES = ['solicitando', 'ofrecido', 'aceptado', 'en_camino', 'llego', 'a_bordo'];

    public function passenger()
    {
        return $this->belongsTo(Passenger::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    /* ---- Helpers ---- */

    public static function makeCode(): string
    {
        $n = (int) (self::max('id') ?? 0) + 1;
        return 'MG-R-' . str_pad($n, 4, '0', STR_PAD_LEFT);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATES, true);
    }

    public function statusLabel(): string
    {
        return [
            'solicitando'  => 'Buscando conductor',
            'ofrecido'     => 'Confirma tu conductor',
            'aceptado'     => 'Conductor asignado',
            'en_camino'    => 'El conductor va en camino',
            'llego'        => 'El conductor llegó',
            'a_bordo'      => 'En viaje',
            'completado'   => 'Completado',
            'cancelado'    => 'Cancelado',
            'sin_conductor'=> 'Sin conductores disponibles',
        ][$this->status] ?? $this->status;
    }
}
