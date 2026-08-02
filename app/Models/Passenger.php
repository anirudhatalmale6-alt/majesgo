<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Passenger extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected $casts = [
        'rating'         => 'decimal:2',
        'last_active_at' => 'datetime',
    ];

    public function rides()
    {
        return $this->hasMany(Ride::class)->latest();
    }

    public function activeRide()
    {
        return $this->hasMany(Ride::class)
            ->whereIn('status', Ride::ACTIVE_STATES)
            ->latest()
            ->first();
    }
}
