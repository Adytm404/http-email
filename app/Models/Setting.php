<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    public const DEFAULTS = [
        'default_from' => null,
        'retention_days' => 30,
        'breaker_threshold' => 3,
        'breaker_cooldown_seconds' => 300,
    ];

    /** Settings with their own handling (captcha_secret is stored encrypted and never sent to the browser). */
    public const CAPTCHA_DEFAULTS = [
        'captcha_mode' => 'off',
        'captcha_site_key' => null,
        'captcha_secret' => null,
        'captcha_min_score' => 0.5,
    ];

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key): mixed
    {
        $all = Cache::remember('settings.all', 60, fn () => self::pluck('value', 'key')->all());

        return $all[$key] ?? self::DEFAULTS[$key] ?? self::CAPTCHA_DEFAULTS[$key] ?? null;
    }

    public static function put(string $key, mixed $value): void
    {
        self::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings.all');
    }
}
