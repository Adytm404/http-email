<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RoutingRule extends Model
{
    public const STRATEGIES = [
        'failover' => 'Priority + failover',
        'weighted' => 'Weighted round-robin',
    ];

    public const CONDITION_KEYS = ['from_domain', 'to_domain', 'tag', 'api_key_id'];

    protected $fillable = ['name', 'priority', 'is_default', 'strategy', 'conditions', 'is_active'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function providers(): BelongsToMany
    {
        return $this->belongsToMany(Provider::class)
            ->withPivot(['priority', 'weight'])
            ->orderByPivot('priority');
    }

    public function conditionSummary(): string
    {
        $c = array_filter($this->conditions ?? [], fn ($v) => $v !== null && $v !== '');
        if ($this->is_default || $c === []) {
            return 'All mail';
        }

        $labels = ['from_domain' => 'from', 'to_domain' => 'to', 'tag' => 'tag', 'api_key_id' => 'key'];
        $parts = [];
        foreach ($c as $k => $v) {
            if ($k === 'api_key_id') {
                $v = ApiKey::find($v)?->name ?? "#{$v}";
            }
            $parts[] = ($labels[$k] ?? $k).' = '.$v;
        }

        return implode(' · ', $parts);
    }
}
