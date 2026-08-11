<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cada foto que un conductor envía (rostro o vehículo) pasa por aquí antes de que
 * la vea un pasajero. Se guarda el historial completo: sirve para saber quién aprobó
 * qué y por qué se rechazó algo.
 */
class DriverPhoto extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['reviewed_at' => 'datetime'];

    public const TYPES = ['perfil', 'vehiculo'];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function typeLabel(): string
    {
        return ['perfil' => 'Foto de perfil', 'vehiculo' => 'Foto del vehículo'][$this->type] ?? $this->type;
    }

    public function statusLabel(): string
    {
        return [
            'pendiente' => 'Pendiente de aprobación',
            'aprobado'  => 'Aprobada',
            'rechazado' => 'Rechazada',
        ][$this->status] ?? $this->status;
    }

    public function url(): ?string
    {
        return \App\Services\ImageStore::url($this->path);
    }

    public function scopePending($q)
    {
        return $q->where('status', 'pendiente');
    }
}
