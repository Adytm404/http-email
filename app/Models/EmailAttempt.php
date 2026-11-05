<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['email_id', 'provider_id', 'status', 'error_code', 'error_message', 'response', 'duration_ms'];

    protected function casts(): array
    {
        return ['response' => 'array'];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
