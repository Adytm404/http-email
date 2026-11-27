<?php

namespace App\Livewire;

use App\Models\Email;
use App\Models\Provider;
use App\Routing\QuotaManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Overview')]
class Overview extends Component
{
    public const RANGES = [1 => 'Today', 7 => '7 days', 14 => '14 days', 30 => '30 days'];

    /** One colour per provider in the volume chart (by provider id, so colours stay put). */
    public const PALETTE = ['#3b82f6', '#8b5cf6', '#14b8a6', '#ec4899', '#84cc16', '#06b6d4', '#6366f1', '#94a3b8'];

    #[Url(except: 7)]
    public int $range = 7;

    public function mount(): void
    {
        $this->authorize('view_dashboard');
        if (! array_key_exists($this->range, self::RANGES)) {
            $this->range = 7;
        }
    }

    public function render()
    {
        $range = array_key_exists($this->range, self::RANGES) ? $this->range : 7;
        $today = CarbonImmutable::today();
        $since = $today->subDays($range - 1);
        $inRange = fn () => Email::where('created_at', '>=', $since);

        // "Today" is charted per hour, every other range per day.
        $hourly = $range === 1;
        $bucket = $hourly ? "to_char(date_trunc('hour', created_at), 'YYYY-MM-DD HH24')" : "date_trunc('day', created_at)::date";

        $daily = $inRange()
            ->whereIn('status', ['sent', 'failed'])
            ->selectRaw("{$bucket} as day, status, count(*) as n")
            ->groupBy('day', 'status')
            ->get()
            ->groupBy(fn ($r) => (string) $r->day);

        $providers = Provider::orderBy('priority')->orderBy('id')->get();
        $colors = $providers->sortBy('id')->values()->mapWithKeys(fn ($p, $i) => [$p->id => self::PALETTE[$i % count(self::PALETTE)]])->all();

        // Delivered emails per bucket and provider, so each bar can show who sent what.
        $byProviderPerBucket = $inRange()->where('status', 'sent')->whereNotNull('provider_id')
            ->selectRaw("{$bucket} as day, provider_id, count(*) as n")
            ->groupBy('day', 'provider_id')
            ->get()
            ->groupBy(fn ($r) => (string) $r->day);

        // What the active providers can send per bucket under their limits (providers without a limit add nothing).
        $unit = $hourly ? 'hour' : 'day';
        $active = $providers->where('is_active', true);
        $capacity = (int) $active->sum(fn ($p) => $p->capacity($unit) ?? 0);
        $unlimited = $active->filter(fn ($p) => $p->capacity($unit) === null)->count();

        $days = collect(range(0, ($hourly ? 24 : $range) - 1))->map(function ($i) use ($since, $daily, $hourly, $byProviderPerBucket) {
            $d = $hourly ? $since->addHours($i) : $since->addDays($i);
            $key = $hourly ? $d->format('Y-m-d H') : $d->toDateString();
            $rows = $daily->get($key, collect());

            return [
                'label' => $hourly ? $d->format('H') : $d->format('j'),
                'month' => ! $hourly && ($i === 0 || $d->day === 1) ? $d->format('M') : '',
                'weekday' => $hourly ? $d->format('H') : $d->format('D'),
                'date' => $hourly ? $d->format('D, j M · H:00') : $d->format('D, j M'),
                'by' => $byProviderPerBucket->get($key, collect())->mapWithKeys(fn ($r) => [(int) $r->provider_id => (int) $r->n])->sortKeys()->all(),
                'sent' => (int) $rows->firstWhere('status', 'sent')?->n,
                'failed' => (int) $rows->firstWhere('status', 'failed')?->n,
            ];
        });

        $statusCounts = $inRange()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n);
        $sent = $statusCounts['sent'] ?? 0;
        $failed = $statusCounts['failed'] ?? 0;
        $total = $statusCounts->sum();

        // Today is compared with yesterday up to the same time of day, so an early morning is not a false drop.
        $prevSent = Email::where('status', 'sent')
            ->whereBetween('created_at', $range === 1
                ? [$since->subDay(), CarbonImmutable::now()->subDay()]
                : [$since->subDays($range), $since])
            ->count();

        $avgSeconds = $inRange()->where('status', 'sent')->whereNull('scheduled_at')
            ->selectRaw('avg(extract(epoch from (sent_at - created_at))) as s')->value('s');

        $byProvider = $inRange()->where('status', 'sent')->whereNotNull('provider_id')
            ->select('provider_id', DB::raw('count(*) as n'))->groupBy('provider_id')->pluck('n', 'provider_id');

        $topDomains = DB::table('emails')
            ->crossJoin(DB::raw("jsonb_array_elements_text(emails.\"to\") as rcpt(addr)"))
            ->where('emails.created_at', '>=', $since)
            ->selectRaw("lower(substring(rcpt.addr from '@([^>\\s]+)')) as domain, count(*) as n")
            ->groupBy('domain')
            ->orderByDesc('n')
            ->limit(6)
            ->get();

        $maxDay = max(1, $days->max(fn ($d) => $d['sent'] + $d['failed']));

        // Draw the quota line only when it is within reach of the bars; far above them it would flatten the chart.
        $capacityOnScale = $capacity > 0 && $capacity <= $maxDay * 8;
        $scaleMax = $capacityOnScale ? max($maxDay, $capacity) : $maxDay;

        // Live quota per active provider with a limit: used / limit for each window right now.
        $quota = app(QuotaManager::class);
        $quotaRows = $active->map(function ($p) use ($quota, $colors) {
            $usage = $quota->usage($p);
            if ($usage === []) {
                return null;
            }
            $ratio = collect($usage)->map(fn ($u) => $u['used'] / $u['limit'])->max();

            return [
                'id' => $p->id,
                'name' => $p->name,
                'color' => $colors[$p->id],
                'usage' => $usage,
                'pct' => (int) round($ratio * 100),
                'full' => $ratio >= 1,
                'left' => (int) collect($usage)->map(fn ($u) => max(0, $u['limit'] - $u['used']))->min(),
            ];
        })->filter()->sortByDesc('pct')->values();
        $nearLimit = $quotaRows->filter(fn ($q) => $q['pct'] >= 80)->values();

        return view('livewire.overview', [
            'days' => $days,
            'maxDay' => $maxDay,
            'axis' => $this->axis($scaleMax),
            'capacity' => $capacity,
            'capacityOnScale' => $capacityOnScale,
            'unlimited' => $unlimited,
            'colors' => $colors,
            'names' => $providers->pluck('name', 'id')->all(),
            'quotaRows' => $quotaRows,
            'nearLimit' => $nearLimit,
            'sent' => $sent,
            'failed' => $failed,
            'total' => $total,
            'pending' => Email::whereIn('status', ['queued', 'sending', 'scheduled'])->count(),
            'successRate' => ($sent + $failed) > 0 ? round($sent / ($sent + $failed) * 100, 1) : null,
            'trend' => $prevSent > 0 ? round(($sent - $prevSent) / $prevSent * 100) : null,
            'avgSeconds' => $avgSeconds !== null ? (float) $avgSeconds : null,
            'statusCounts' => $statusCounts,
            'providers' => $providers,
            'byProvider' => $byProvider,
            'providerTotal' => max(1, $byProvider->sum()),
            'topDomains' => $topDomains,
            'recent' => Email::with('provider')->latest()->limit(6)->get(),
            'ranges' => self::RANGES,
            'hourly' => $hourly,
        ]);
    }

    /** Rounded gridline values for the chart's y axis. */
    private function axis(int $max): array
    {
        $step = max(1, (int) ceil($max / 4));
        $magnitude = 10 ** max(0, strlen((string) $step) - 1);
        $step = (int) (ceil($step / $magnitude) * $magnitude);

        return ['step' => $step, 'top' => $step * 4];
    }
}
