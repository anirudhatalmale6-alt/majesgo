<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverDocument extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'expires_at'  => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function type()
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * ¿Este documento habilita hoy?
     *
     * Aprobado no alcanza: un SOAT aprobado en marzo y vencido en agosto deja al conductor
     * sin habilitación aunque la fila siga diciendo 'aprobado'. El vencimiento se compara
     * al momento de preguntar, no se guarda un estado 'vencido' que alguien tenga que ir a
     * escribir — un estado que depende de una tarea que puede no correr miente.
     */
    public function vigente(): bool
    {
        if ($this->status !== 'aprobado') {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->endOfDay()->isFuture();
    }

    public function vencido(): bool
    {
        return $this->status === 'aprobado'
            && $this->expires_at !== null
            && $this->expires_at->endOfDay()->isPast();
    }

    /** Días que faltan para vencer (negativo si ya venció, null si no caduca). */
    public function diasParaVencer(): ?int
    {
        return $this->expires_at ? (int) now()->startOfDay()->diffInDays($this->expires_at, false) : null;
    }
}
