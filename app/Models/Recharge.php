<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recharge extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount'       => 'decimal:2',
        'validated_at' => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function statusLabel(): string
    {
        return [
            'pendiente' => 'Pendiente',
            'aprobado'  => 'Aprobado',
            'rechazado' => 'Rechazado',
        ][$this->status] ?? $this->status;
    }

    public function methodLabel(): string
    {
        return [
            'yape'          => 'Yape',
            'transferencia' => 'Transferencia',
            'admin'         => 'Carga manual',
        ][$this->method] ?? $this->method;
    }
}
