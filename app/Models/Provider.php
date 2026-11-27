<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    public const TYPES = [
        'smtp' => 'SMTP',
        'resend' => 'Resend',
        'sendgrid' => 'SendGrid',
        'mailgun' => 'Mailgun',
    ];

    protected $fillable = [
        'name', 'type', 'from_address', 'config', 'is_active', 'priority', 'weight',
        'limit_per_minute', 'limit_per_hour', 'limit_per_day',
        'health', 'consecutive_failures', 'cooldown_until', 'last_error', 'last_used_at',
    ];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
            'cooldown_until' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function routingRules(): BelongsToMany
    {
        return $this->belongsToMany(RoutingRule::class)->withPivot(['priority', 'weight']);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(EmailAttempt::class);
    }

    public function isCoolingDown(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    public function isAvailable(): bool
    {
        return $this->is_active && ! $this->isCoolingDown();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Limits keyed by window name, only those that are set. */
    /**
     * The most this provider can send in an hour or a day under its limits, or null when it has none.
     * A per-minute limit counts for every minute of the window, and a daily limit also caps an hour.
     */
    public function capacity(string $per): ?int
    {
        $l = $this->limits();
        $caps = $per === 'hour'
            ? array_filter([$l['hour'] ?? null, isset($l['minute']) ? $l['minute'] * 60 : null, $l['day'] ?? null])
            : array_filter([$l['day'] ?? null, isset($l['hour']) ? $l['hour'] * 24 : null, isset($l['minute']) ? $l['minute'] * 1440 : null]);

        return $caps === [] ? null : (int) min($caps);
    }

    public function limits(): array
    {
        return array_filter([
            'minute' => $this->limit_per_minute,
            'hour' => $this->limit_per_hour,
            'day' => $this->limit_per_day,
        ], fn ($v) => $v !== null && $v > 0);
    }
}
