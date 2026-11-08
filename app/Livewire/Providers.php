<?php

namespace App\Livewire;

use App\Mail\DriverException;
use App\Mail\DriverFactory;
use App\Mail\OutgoingMessage;
use App\Models\Provider;
use App\Models\Setting;
use App\Routing\QuotaManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Address;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Providers')]
class Providers extends Component
{
    /** Runs on every request, including Livewire actions. */
    public function boot(): void
    {
        $this->authorize('manage_providers');
    }

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public array $config = [];

    public ?int $testingId = null;

    public string $testFrom = '';

    public string $testTo = '';

    public function mount(): void
    {
        $this->resetForm();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Provider::findOrFail($id);
        $this->editingId = $p->id;
        $this->form = $p->only(['name', 'type', 'from_address', 'is_active', 'priority', 'weight', 'limit_per_minute', 'limit_per_hour', 'limit_per_day']);

        // Secrets are never sent to the browser; blank means "keep current".
        $this->config = collect(DriverFactory::fields()[$p->type])
            ->mapWithKeys(fn ($f) => [$f['key'] => ! empty($f['secret']) ? '' : ($p->config[$f['key']] ?? $f['default'] ?? '')])
            ->all();
        $this->showForm = true;
    }

    public function updatedFormType(): void
    {
        $this->config = $this->defaultConfig($this->form['type']);
    }

    public function save(): void
    {
        $fields = DriverFactory::fields()[$this->form['type']] ?? [];
        $existing = $this->editingId ? Provider::findOrFail($this->editingId) : null;

        // The provider type cannot change while editing; only the radio is disabled client-side.
        if ($existing && ($this->form['type'] ?? null) !== $existing->type) {
            throw ValidationException::withMessages(['form.type' => 'The provider type cannot be changed. Create a new provider instead.']);
        }

        // A stored secret may only be kept while the connection details it was saved with are unchanged.
        // Otherwise a blank password would be silently sent to whatever host/user was just typed in.
        $keepSecrets = $existing && ! $this->connectionChanged($fields, $existing);

        $rules = [
            'form.name' => ['required', 'string', 'max:100'],
            'form.type' => ['required', Rule::in(array_keys(Provider::TYPES))],
            'form.from_address' => ['nullable', 'string', 'max:320', function (string $attribute, mixed $value, \Closure $fail) {
                try {
                    if (trim((string) $value) !== '') {
                        Address::create(trim((string) $value));
                    }
                } catch (\Throwable) {
                    $fail('Enter a valid sender, like noreply@example.com or Acme <noreply@example.com>.');
                }
            }],
            'form.priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'form.weight' => ['required', 'integer', 'min:0', 'max:1000'],
            'form.limit_per_minute' => ['nullable', 'integer', 'min:1'],
            'form.limit_per_hour' => ['nullable', 'integer', 'min:1'],
            'form.limit_per_day' => ['nullable', 'integer', 'min:1'],
        ];
        foreach ($fields as $f) {
            $required = ! empty($f['required']) && ! (! empty($f['secret']) && $keepSecrets);
            $rules['config.'.$f['key']] = [$required ? 'required' : 'nullable', $f['type'] === 'number' ? 'integer' : 'string'];
        }
        $messages = [];
        foreach ($fields as $f) {
            if (! empty($f['secret']) && $existing && ! $keepSecrets) {
                $rules['config.'.$f['key']][0] = 'required';
                $messages['config.'.$f['key'].'.required'] = 'Re-enter the '.strtolower($f['label']).': the connection details changed, so the saved one is not reused.';
            }
        }
        $this->validate($rules, $messages, collect($fields)->mapWithKeys(fn ($f) => ['config.'.$f['key'] => strtolower($f['label'])])->all());

        $config = [];
        foreach ($fields as $f) {
            $value = $this->config[$f['key']] ?? null;
            if (! empty($f['secret']) && ($value === null || $value === '') && $keepSecrets) {
                $value = $existing->config[$f['key']] ?? null;
            }
            $config[$f['key']] = $f['type'] === 'number' && $value !== null && $value !== '' ? (int) $value : $value;
        }

        $data = array_merge($this->form, [
            'config' => $config,
            'from_address' => trim((string) ($this->form['from_address'] ?? '')) ?: null,
            'limit_per_minute' => $this->form['limit_per_minute'] ?: null,
            'limit_per_hour' => $this->form['limit_per_hour'] ?: null,
            'limit_per_day' => $this->form['limit_per_day'] ?: null,
        ]);

        if ($existing) {
            $existing->update($data);
        } else {
            Provider::create($data);
        }

        $this->showForm = false;
        $this->dispatch('toast', message: $existing ? 'Provider updated' : 'Provider added');
    }

    /** True when any non-secret field (host, port, user, domain, ...) differs from what is stored. */
    private function connectionChanged(array $fields, Provider $existing): bool
    {
        foreach ($fields as $f) {
            if (! empty($f['secret'])) {
                continue;
            }
            $stored = $existing->config[$f['key']] ?? $f['default'] ?? '';
            if ((string) ($this->config[$f['key']] ?? '') !== (string) $stored) {
                return true;
            }
        }

        return false;
    }

    public function toggle(int $id): void
    {
        $p = Provider::findOrFail($id);
        $p->update(['is_active' => ! $p->is_active]);
    }

    public function resetHealth(int $id): void
    {
        Provider::findOrFail($id)->update(['health' => 'healthy', 'consecutive_failures' => 0, 'cooldown_until' => null]);
        $this->dispatch('toast', message: 'Health reset');
    }

    public function delete(int $id): void
    {
        Provider::findOrFail($id)->delete();
        $this->dispatch('toast', message: 'Provider deleted');
    }

    public function testConnection(int $id, DriverFactory $drivers): void
    {
        $p = Provider::findOrFail($id);

        try {
            $drivers->make($p)->test();
            $this->dispatch('toast', message: "{$p->name}: connection OK");
        } catch (DriverException $e) {
            $this->dispatch('toast', type: 'error', message: "{$p->name}: ".Str::limit($e->getMessage(), 160));
        } catch (Throwable $e) {
            $this->dispatch('toast', type: 'error', message: "{$p->name}: ".Str::limit($e->getMessage(), 160));
        }
    }

    public function openTest(int $id): void
    {
        $this->testingId = $id;
        $this->testFrom = (string) (Provider::find($id)?->from_address ?: Setting::get('default_from'));
        $this->testTo = $this->testTo ?: (string) auth()->user()?->email;
    }

    public function sendTest(DriverFactory $drivers): void
    {
        $this->validate(['testFrom' => 'required|string', 'testTo' => 'required|email']);
        $p = Provider::findOrFail($this->testingId);

        $message = new OutgoingMessage(
            id: (string) Str::uuid(),
            from: $this->testFrom,
            to: [$this->testTo],
            subject: "Test from mailroute via {$p->name}",
            html: '<p>This is a test email sent through <strong>'.e($p->name).'</strong> ('.e($p->typeLabel()).').</p><p>If you can read this, the provider works.</p>',
            text: "This is a test email sent through {$p->name} ({$p->typeLabel()}).",
        );

        try {
            $result = $drivers->make($p)->send($message);
            $this->testingId = null;
            $this->dispatch('toast', message: 'Test email sent'.($result->messageId ? " · {$result->messageId}" : ''));
        } catch (Throwable $e) {
            $this->addError('testTo', Str::limit($e->getMessage(), 300));
        }
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '', 'type' => 'smtp', 'from_address' => '', 'is_active' => true, 'priority' => 10, 'weight' => 100,
            'limit_per_minute' => null, 'limit_per_hour' => null, 'limit_per_day' => null,
        ];
        $this->config = $this->defaultConfig('smtp');
        $this->resetErrorBag();
    }

    private function defaultConfig(string $type): array
    {
        return collect(DriverFactory::fields()[$type] ?? [])->mapWithKeys(fn ($f) => [$f['key'] => $f['default'] ?? ''])->all();
    }

    public function render(QuotaManager $quota)
    {
        $providers = Provider::withCount(['attempts as sent_24h' => fn ($q) => $q->where('status', 'sent')->where('created_at', '>=', now()->subDay())])
            ->orderBy('priority')->orderBy('id')->get();

        return view('livewire.providers', [
            'providers' => $providers,
            'usage' => $providers->mapWithKeys(fn ($p) => [$p->id => $quota->usage($p)]),
            'fields' => DriverFactory::fields()[$this->form['type']] ?? [],
        ]);
    }
}
