<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $fillable = [
        'domain',
        'expires_at',
        'registrar',
        'raw_whois',
        'last_checked_at',
        'last_expiry_notified_at',
        'last_expiry_notified_days',
        'status',
        'last_error',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'last_expiry_notified_at' => 'datetime',
    ];
}
