<?php

namespace App\Livewire;

use App\Models\Email;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Overview')]
class Overview extends Component
{
    public function render()
    {
        $today = CarbonImmutable::today();
        $since = $today->subDays(6);

        $todayCounts = Email::where('created_at', '>=', $today)
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $daily = Email::where('created_at', '>=', $since)
            ->whereIn('status', ['sent', 'failed'])
            ->selectRaw("date_trunc('day', created_at)::date as day, status, count(*) as n")
            ->groupBy('day', 'status')
            ->get()
            ->groupBy(fn ($r) => (string) $r->day);

        $days = collect(range(0, 6))->map(function ($i) use ($since, $daily) {
            $d = $since->addDays($i);
            $rows = $daily->get($d->toDateString(), collect());

            return [
                'label' => $d->format('D'),
                'date' => $d->format('j M'),
                'sent' => (int) $rows->firstWhere('status', 'sent')?->n,
                'failed' => (int) $rows->firstWhere('status', 'failed')?->n,
            ];
        });

        $week = ['sent' => $days->sum('sent'), 'failed' => $days->sum('failed')];
        $done = $week['sent'] + $week['failed'];

        $byProvider = Email::where('created_at', '>=', $since)->where('status', 'sent')->whereNotNull('provider_id')
            ->select('provider_id', DB::raw('count(*) as n'))->groupBy('provider_id')->pluck('n', 'provider_id');

        return view('livewire.overview', [
            'sentToday' => (int) ($todayCounts['sent'] ?? 0),
            'failedToday' => (int) ($todayCounts['failed'] ?? 0),
            'pending' => Email::whereIn('status', ['queued', 'sending', 'scheduled'])->count(),
            'successRate' => $done > 0 ? round($week['sent'] / $done * 100, 1) : null,
            'days' => $days,
            'maxDay' => max(1, $days->max(fn ($d) => $d['sent'] + $d['failed'])),
            'providers' => Provider::orderBy('priority')->get(),
            'byProvider' => $byProvider,
            'weekSent' => max(1, $week['sent']),
            'recent' => Email::with('provider')->latest()->limit(8)->get(),
        ]);
    }
}
