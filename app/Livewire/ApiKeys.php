<?php

namespace App\Livewire;

use App\Models\ApiKey;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('API keys')]
class ApiKeys extends Component
{
    public bool $showForm = false;

    public string $name = '';

    public string $permission = 'full_access';

    /** Shown once right after creation. */
    public ?string $newToken = null;

    public function create(): void
    {
        $this->reset('name', 'newToken');
        $this->permission = 'full_access';
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'permission' => ['required', Rule::in(array_keys(ApiKey::PERMISSIONS))],
        ]);

        [, $token] = ApiKey::generate($this->name, $this->permission);
        $this->newToken = $token;
    }

    public function close(): void
    {
        $this->showForm = false;
        $this->newToken = null;
    }

    public function revoke(int $id): void
    {
        ApiKey::findOrFail($id)->update(['revoked_at' => now()]);
        $this->dispatch('toast', message: 'Key revoked');
    }

    public function render()
    {
        return view('livewire.api-keys', [
            'keys' => ApiKey::withCount('emails')->orderByRaw('revoked_at is not null')->latest()->get(),
        ]);
    }
}
