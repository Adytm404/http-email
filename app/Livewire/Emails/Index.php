<?php

namespace App\Livewire\Emails;

use App\Models\Email;
use App\Models\Provider;
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

    public function render()
    {
        $emails = Email::with('provider')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->provider !== '', fn ($q) => $q->where('provider_id', $this->provider))
            ->when($this->range !== '', fn ($q) => $q->where('created_at', '>=', match ($this->range) {
                '1h' => now()->subHour(),
                '24h' => now()->subDay(),
                '7d' => now()->subDays(7),
                default => now()->subDays(30),
            }))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
                $q->where(fn ($q) => $q->where('subject', 'ilike', $term)
                    ->orWhere('from', 'ilike', $term)
                    ->orWhereRaw('"to"::text ilike ?', [$term])
                    ->orWhereRaw('id::text ilike ?', [$term]));
            })
            ->latest()
            ->paginate(25);

        return view('livewire.emails.index', [
            'emails' => $emails,
            'providers' => Provider::orderBy('name')->get(),
        ]);
    }
}
