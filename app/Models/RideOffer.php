<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Una oferta de un conductor a un pasajero (ver la migración create_ride_offers_table). */
class RideOffer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'base_price'    => 'decimal:2',
        'counter_offer' => 'decimal:2',
        'total'         => 'decimal:2',
        'libre'         => 'boolean',
        'decided_at'    => 'datetime',
    ];

    public const OUTCOMES = [
        'pendiente' => 'Esperando',
        'aceptado'  => 'Aceptó',
        'rechazado' => 'Rechazó',
        'vencido'   => 'No respondió',
        'cancelado' => 'Canceló el viaje',
    ];

    public function ride()
    {
        return $this->belongsTo(Ride::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class)->withTrashed();
    }

    /** Cierra la oferta pendiente de ese viaje con el resultado dado. */
    public static function cerrar(int $rideId, string $outcome): void
    {
        static::where('ride_id', $rideId)->where('outcome', 'pendiente')
            ->update(['outcome' => $outcome, 'decided_at' => now()]);
    }
}
