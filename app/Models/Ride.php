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
        'approach_fee'     => 'decimal:2',
        'counter_offer'    => 'decimal:2',
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
        'driver_reported_at' => 'datetime',
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

    public function messages()
    {
        return $this->hasMany(RideMessage::class);
    }

    /* ---- Viajes reales vs. de prueba ---- */

    /*
     * La columna is_demo no alcanza para saber si un viaje fue real. Cuando un viaje se
     * re-emite (el conductor no contestó y se busca otro) vuelve a is_demo = false, y si
     * después lo toma el conductor de simulación el viaje queda marcado como real. Por eso
     * lo que manda es QUIÉN lo hizo: si el conductor es de simulación, no fue un viaje del
     * negocio. withTrashed(): un conductor demo dado de baja no debe convertir en reales
     * los viajes que hizo.
     */
    public function scopeReal($q)
    {
        return $q->where('is_demo', false)
            ->whereDoesntHave('driver', fn ($d) => $d->withTrashed()->where('is_demo', true));
    }

    public function scopeDemo($q)
    {
        return $q->where(fn ($w) => $w->where('is_demo', true)
            ->orWhereHas('driver', fn ($d) => $d->withTrashed()->where('is_demo', true)));
    }

    /**
     * ¿Es un viaje de la cuenta de revisión de las tiendas? Esos NUNCA pueden llegar a un
     * conductor real: el revisor prueba desde otro país y a cualquier hora, y un conductor
     * de Majes saldría a recoger a nadie.
     *
     * ⚠ No sirve preguntar por `is_demo`: Dispatch::releaseOffer() lo pone en false al
     * re-emitir, así que en cuanto el revisor rechaza al conductor de prueba —o simplemente
     * deja vencer los 15 s de confirmación— el viaje volvería a la búsqueda marcado como
     * real. Lo que no cambia nunca es QUIÉN lo pidió.
     */
    public function esDeRevision(): bool
    {
        return (bool) ($this->passenger?->is_reviewer);
    }

    public function isDemo(): bool
    {
        return (bool) $this->is_demo || (bool) $this->driver?->is_demo;
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

    /**
     * Total pactado: tramo A→B + costo de aproximación + ajuste (contraoferta) del conductor
     * que tomó el viaje. Mientras nadie lo haya tomado, los dos últimos son 0 y el total es
     * solo el viaje.
     */
    public function totalPrice(): float
    {
        return \App\Services\Fare::total(
            (float) $this->offered_price,
            (float) $this->approach_fee,
            (float) $this->counter_offer,
        );
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
