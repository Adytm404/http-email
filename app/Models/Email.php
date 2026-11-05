<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Email extends Model
{
    use HasUuids;

    public const STATUSES = ['scheduled', 'queued', 'sending', 'sent', 'failed', 'canceled'];

    protected $fillable = [
        'api_key_id', 'from', 'to', 'cc', 'bcc', 'reply_to', 'subject', 'html', 'text',
        'headers', 'tags', 'status', 'routing_rule_id', 'provider_id', 'forced_route',
        'provider_message_id', 'attempts', 'scheduled_at', 'sent_at', 'failed_reason', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'reply_to' => 'array',
            'headers' => 'array',
            'tags' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function routingRule(): BelongsTo
    {
        return $this->belongsTo(RoutingRule::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function attemptLogs(): HasMany
    {
        return $this->hasMany(EmailAttempt::class)->orderBy('id');
    }

    /** Recipient domains across to/cc/bcc, lowercased. */
    public function recipientDomains(): array
    {
        $all = array_merge($this->to ?? [], $this->cc ?? [], $this->bcc ?? []);

        return array_values(array_unique(array_map(fn ($a) => self::domainOf($a), $all)));
    }

    public function fromDomain(): string
    {
        return self::domainOf($this->from);
    }

    public static function domainOf(string $address): string
    {
        if (preg_match('/<([^>]+)>/', $address, $m)) {
            $address = $m[1];
        }

        $at = strrchr($address, '@');

        return $at === false ? '' : strtolower(trim(substr($at, 1)));
    }

    /** Resend-style last_event name. */
    public function lastEvent(): string
    {
        return match ($this->status) {
            'sent' => 'delivered',
            'failed' => 'failed',
            'canceled' => 'canceled',
            'scheduled' => 'scheduled',
            default => 'queued',
        };
    }
}
