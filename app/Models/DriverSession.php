<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverSession extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];
}
