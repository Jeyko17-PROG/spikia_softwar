<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SessionUsageEvent extends Model
{
    protected $fillable = [
        'user_id',
        'sesion_id',
        'slug',
        'action',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];
}
