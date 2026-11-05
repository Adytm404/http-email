<?php

namespace App\Livewire\Emails;

use App\Models\Email;
use App\Models\Provider;
use App\Services\EmailIntake;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Emails')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $provider = '';

    #[Url(except: '')]
    public string $range = '';

    /** Email shown in the side drawer. */
    #[Url(as: 'view', except: null)]
    public ?string $previewId = null;

    public function mount(): void
    {
        $this->authorize('view_emails');
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'status', 'provider', 'range'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'provider', 'range');
        $this->resetPage();
    }

    public function open(string $id): void
    {
        $this->previewId = $id;
    }

    public function close(): void
    {
        $this->previewId = null;
    }

    public function retry(string $id, EmailIntake $intake): void
    {
        $this->authorize('manage_emails');
        $email = Email::findOrFail($id);
        if (in_array($email->status, ['failed', 'canceled'], true)) {
            $email->forceFill(['status' => 'queued', 'failed_reason' => null, 'scheduled_at' => null])->save();
            $intake->dispatch($email);
            $this->dispatch('toast', message: 'Queued for another attempt');
        }
    }

    /** Re-queue every failed email that matches the current filters. */
    public function retryFailed(EmailIntake $intake): void
    {
        $this->authorize('manage_emails');

        $count = 0;
        $this->filtered()->where('status', 'failed')->select('id')->chunkById(200, function ($emails) use ($intake, &$count) {
            foreach ($emails as $email) {
                $email->forceFill(['status' => 'queued', 'failed_reason' => null])->save();
                $intake->dispatch($email);
                $count++;
            }
        });

        $this->dispatch('toast', message: $count ? "{$count} failed ".str('email')->plural($count).' queued again' : 'No failed emails match these filters');
    }

    /** Query with the active filters, shared by the table, export and bulk retry. */
    public function filtered(): Builder
    {
        return self::applyFilters(Email::query(), [
            'search' => $this->search,
            'status' => $this->status,
            'provider' => $this->provider,
            'range' => $this->range,
        ]);
    }

    public static function applyFilters(Builder $query, array $f): Builder
    {
        return $query
            ->when(($f['status'] ?? '') !== '', fn ($q) => $q->where('status', $f['status']))
            ->when(($f['provider'] ?? '') !== '', fn ($q) => $q->where('provider_id', $f['provider']))
            ->when(($f['range'] ?? '') !== '', fn ($q) => $q->where('created_at', '>=', match ($f['range']) {
                '1h' => now()->subHour(),
                '24h' => now()->subDay(),
                '7d' => now()->subDays(7),
                default => now()->subDays(30),
            }))
            ->when(trim($f['search'] ?? '') !== '', function ($q) use ($f) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($f['search'])).'%';
                $q->where(fn ($q) => $q->where('subject', 'ilike', $term)
                    ->orWhere('from', 'ilike', $term)
                    ->orWhereRaw('"to"::text ilike ?', [$term])
                    ->orWhereRaw('id::text ilike ?', [$term]));
            });
    }

    public function render()
    {
        $preview = $this->previewId && preg_match('/^[0-9a-f-]{36}$/i', $this->previewId)
            ? Email::with(['provider', 'attachments', 'attemptLogs.provider', 'routingRule'])->find($this->previewId)
            : null;

        $counts = Email::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('livewire.emails.index', [
            'emails' => $this->filtered()->with('provider')->withCount('attachments')->latest()->paginate(25),
            'providers' => Provider::orderBy('name')->get(),
            'preview' => $preview,
            'counts' => $counts,
            'exportUrl' => route('emails.export', array_filter([
                'search' => $this->search, 'status' => $this->status, 'provider' => $this->provider, 'range' => $this->range,
            ])),
        ]);
    }
}
