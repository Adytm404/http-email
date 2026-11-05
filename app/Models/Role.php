<?php

namespace App\Models;

use App\Auth\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $fillable = ['name', 'description', 'permissions', 'is_owner'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_owner' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function allows(string $permission): bool
    {
        return $this->is_owner || in_array($permission, $this->permissions ?? [], true);
    }

    /** Permissions that are actually in effect (owner = all). */
    public function effectivePermissions(): array
    {
        return $this->is_owner ? Permission::keys() : array_values(array_intersect(Permission::keys(), $this->permissions ?? []));
    }

    public static function owner(): ?self
    {
        return self::where('is_owner', true)->first();
    }
}
