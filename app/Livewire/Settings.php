<?php

namespace App\Livewire;

use App\Models\Setting;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class Settings extends Component
{
    /** Runs on every request, including Livewire actions. */
    public function boot(): void
    {
        $this->authorize('manage_settings');
    }

    public ?string $default_from = '';

    public int $retention_days = 30;

    public int $breaker_threshold = 3;

    public int $breaker_cooldown_seconds = 300;

    public function mount(): void
    {
        foreach (array_keys(Setting::DEFAULTS) as $key) {
            $this->{$key} = Setting::get($key) ?? $this->{$key};
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'default_from' => ['nullable', 'string', 'max:320'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'breaker_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'breaker_cooldown_seconds' => ['required', 'integer', 'min:10', 'max:86400'],
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, $value === '' ? null : $value);
        }

        $this->dispatch('toast', message: 'Settings saved');
    }

    public function render()
    {
        return view('livewire.settings');
    }
}
