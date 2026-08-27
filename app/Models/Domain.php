<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'health_check_enabled',
        'health_url',
        'health_status',
        'health_status_code',
        'health_response_time_ms',
        'last_health_checked_at',
        'last_health_error',
        'last_health_notified_at',
        'last_health_notified_status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'last_expiry_notified_at' => 'datetime',
        'health_check_enabled' => 'boolean',
        'last_health_checked_at' => 'datetime',
        'last_health_notified_at' => 'datetime',
    ];

    public function getHealthCheckUrl(): string
    {
        if (filled($this->health_url)) {
            return $this->health_url;
        }

        $host = $this->domain;

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if ($ascii !== false) {
                $host = $ascii;
            }
        }

        return 'https://'.$host;
    }

    public function scopeHealthCheckEnabled(Builder $query): Builder
    {
        return $query->where('health_check_enabled', true);
    }
}
