<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAttachment extends Model
{
    protected $fillable = ['email_id', 'filename', 'content_type', 'content_id', 'path', 'size'];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
