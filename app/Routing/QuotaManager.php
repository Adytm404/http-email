<?php

namespace App\Routing;

use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class QuotaManager
{
    /**
     * Atomically reserve one send against every limit the provider has.
     * Returns false (and reserves nothing) when any window is exhausted.
     */
    public function reserve(Provider $provider): bool
    {
        $limits = $provider->limits();
        if ($limits === []) {
            return true;
        }

        try {
            DB::transaction(function () use ($provider, $limits) {
                $this->reserveAll($provider, $limits);
            });
        } catch (QuotaExceeded) {
            return false;
        }

        return true;
    }

    private function reserveAll(Provider $provider, array $limits): void
    {
        foreach ($limits as $window => $limit) {
            $row = DB::selectOne(
                'INSERT INTO provider_usage (provider_id, "window", bucket_start, "count")
                 VALUES (?, ?, ?, 1)
                 ON CONFLICT (provider_id, "window", bucket_start)
                 DO UPDATE SET "count" = provider_usage."count" + 1
                 WHERE provider_usage."count" < ?
                 RETURNING "count"',
                [$provider->id, $window, $this->bucket($window), $limit]
            );

            if ($row === null) {
                // Thrown inside the transaction, so increments already made for other windows roll back.
                throw new QuotaExceeded("Provider {$provider->id} reached its per-{$window} limit");
            }
        }
    }

    /** Give a reservation back, e.g. when the provider failed before sending. */
    public function release(Provider $provider): void
    {
        foreach (array_keys($provider->limits()) as $window) {
            DB::update(
                'UPDATE provider_usage SET "count" = GREATEST("count" - 1, 0) WHERE provider_id = ? AND "window" = ? AND bucket_start = ?',
                [$provider->id, $window, $this->bucket($window)]
            );
        }
    }

    /**
     * Current usage per window: ['minute' => ['used' => 3, 'limit' => 60], ...]
     */
    public function usage(Provider $provider): array
    {
        $out = [];
        foreach ($provider->limits() as $window => $limit) {
            $used = (int) DB::table('provider_usage')
                ->where('provider_id', $provider->id)
                ->where('window', $window)
                ->where('bucket_start', $this->bucket($window))
                ->value('count');
            $out[$window] = ['used' => $used, 'limit' => $limit];
        }

        return $out;
    }

    public function bucket(string $window): string
    {
        $now = CarbonImmutable::now('UTC');

        $start = match ($window) {
            'minute' => $now->startOfMinute(),
            'hour' => $now->startOfHour(),
            'day' => $now->startOfDay(),
        };

        return $start->format('Y-m-d H:i:s');
    }

    public function prune(): int
    {
        return DB::table('provider_usage')->where('bucket_start', '<', now('UTC')->subDays(2))->delete();
    }
}
