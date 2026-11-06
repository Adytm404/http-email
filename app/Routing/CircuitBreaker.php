<?php

namespace App\Routing;

use App\Models\Provider;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Tracks consecutive provider failures. After `breaker_threshold` failures in
 * a row the provider is marked down and skipped until the cooldown passes;
 * the next request after that acts as the probe.
 */
class CircuitBreaker
{
    public function recordSuccess(Provider $provider): void
    {
        $provider->forceFill([
            'consecutive_failures' => 0,
            'health' => 'healthy',
            'cooldown_until' => null,
            'last_used_at' => now(),
        ])->save();
    }

    /** The provider hit its own sending limit: skip it until the pause ends, then probe again. */
    public function pause(Provider $provider, int $seconds, string $error): void
    {
        $provider->forceFill([
            'health' => 'limited',
            'cooldown_until' => now()->addSeconds($seconds),
            'last_error' => mb_substr($error, 0, 2000),
            'last_used_at' => now(),
        ])->save();
    }

    public function recordFailure(Provider $provider, string $error): void
    {
        $threshold = max(1, (int) Setting::get('breaker_threshold'));
        $cooldown = max(10, (int) Setting::get('breaker_cooldown_seconds'));

        $failures = DB::transaction(function () use ($provider) {
            $row = DB::selectOne(
                'UPDATE providers SET consecutive_failures = consecutive_failures + 1, updated_at = now() WHERE id = ? RETURNING consecutive_failures',
                [$provider->id]
            );

            return (int) ($row->consecutive_failures ?? 1);
        });

        $tripped = $failures >= $threshold;

        $provider->forceFill([
            'consecutive_failures' => $failures,
            'health' => $tripped ? 'down' : 'degraded',
            'cooldown_until' => $tripped ? now()->addSeconds($cooldown) : $provider->cooldown_until,
            'last_error' => mb_substr($error, 0, 2000),
            'last_used_at' => now(),
        ])->save();
    }
}
