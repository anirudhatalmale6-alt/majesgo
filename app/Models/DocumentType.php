<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'required'     => 'boolean',
        'has_expiry'   => 'boolean',
        'needs_number' => 'boolean',
        'active'       => 'boolean',
    ];

    public function documents()
    {
        return $this->hasMany(DriverDocument::class);
    }

    /** Los que hay que pedirle hoy a un conductor, en el orden en que se muestran. */
    public function scopeVigentes($q)
    {
        return $q->where('active', true)->orderBy('sort_order')->orderBy('id');
    }
}
