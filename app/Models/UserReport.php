<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una denuncia de un usuario contra otro, siempre atada a un viaje.
 *
 * Los motivos son una lista cerrada por lado: el pasajero denuncia cosas del conductor
 * (manejo peligroso, cobro distinto) y el conductor denuncia cosas del pasajero (no pagó,
 * daños al vehículo). "otro" existe en los dos y obliga a escribir el detalle.
 */
class UserReport extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['reviewed_at' => 'datetime'];

    /** Motivos que puede elegir el PASAJERO sobre el conductor. */
    public const REASONS_ON_DRIVER = [
        'trato'      => 'Trato irrespetuoso o agresivo',
        'acoso'      => 'Acoso o comentarios ofensivos',
        'manejo'     => 'Manejo peligroso',
        'cobro'      => 'Me cobró un precio distinto al acordado',
        'identidad'  => 'El conductor o el vehículo no eran los de la app',
        'estado'     => 'Parecía estar en estado inadecuado',
        'otro'       => 'Otro motivo',
    ];

    /** Motivos que puede elegir el CONDUCTOR sobre el pasajero. */
    public const REASONS_ON_PASSENGER = [
        'trato'      => 'Trato irrespetuoso o agresivo',
        'acoso'      => 'Acoso o comentarios ofensivos',
        'no_estuvo'  => 'No se presentó en el punto de recojo',
        'no_pago'    => 'No pagó el viaje',
        'danos'      => 'Dañó o ensució el vehículo',
        'estado'     => 'Parecía estar en estado inadecuado',
        'otro'       => 'Otro motivo',
    ];

    /** Lista de motivos válidos según a quién se denuncia. */
    public static function reasonsFor(string $reportedType): array
    {
        return $reportedType === 'driver' ? self::REASONS_ON_DRIVER : self::REASONS_ON_PASSENGER;
    }

    public function ride()
    {
        return $this->belongsTo(Ride::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pendiente');
    }

    public function reasonLabel(): string
    {
        return self::reasonsFor($this->reported_type)[$this->reason] ?? $this->reason;
    }

    /** El denunciante, ya resuelto al modelo que corresponda. */
    public function reporter(): Passenger|Driver|null
    {
        return $this->reporter_type === 'driver'
            ? Driver::withTrashed()->find($this->reporter_id)
            : Passenger::find($this->reporter_id);
    }

    /** El denunciado, ya resuelto al modelo que corresponda. */
    public function reported(): Passenger|Driver|null
    {
        return $this->reported_type === 'driver'
            ? Driver::withTrashed()->find($this->reported_id)
            : Passenger::find($this->reported_id);
    }
}
