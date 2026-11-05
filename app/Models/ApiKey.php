<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    public const PERMISSIONS = [
        'full_access' => 'Full access',
        'sending_access' => 'Sending access',
    ];

    protected $fillable = ['name', 'prefix', 'token_hash', 'permission', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a key and return [model, plain token]. The plain token is never stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function generate(string $name, string $permission = 'full_access'): array
    {
        $token = 're_'.Str::random(40);

        $key = self::create([
            'name' => $name,
            'prefix' => substr($token, 0, 10),
            'token_hash' => hash('sha256', $token),
            'permission' => $permission,
        ]);

        return [$key, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
    }

    public function canRead(): bool
    {
        return $this->permission === 'full_access';
    }
}
