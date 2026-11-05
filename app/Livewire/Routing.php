<?php

namespace App\Livewire;

use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Provider;
use App\Models\RoutingRule;
use App\Routing\EmailRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Routing')]
class Routing extends Component
{
    /** Runs on every request, including Livewire actions. */
    public function boot(): void
    {
        $this->authorize('manage_routing');
    }

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    /** provider_id => ['enabled' => bool, 'priority' => int, 'weight' => int] */
    public array $members = [];

    public array $sim = ['from' => '', 'to' => '', 'tag' => '', 'api_key_id' => ''];

    public ?array $simResult = null;

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
        $rule = RoutingRule::with('providers')->findOrFail($id);
        $this->editingId = $rule->id;
        $this->form = [
            'name' => $rule->name,
            'strategy' => $rule->strategy,
            'is_default' => $rule->is_default,
            'is_active' => $rule->is_active,
            'conditions' => array_merge(array_fill_keys(RoutingRule::CONDITION_KEYS, ''), $rule->conditions ?? []),
        ];
        $this->members = $this->blankMembers();
        foreach ($rule->providers as $p) {
            $this->members[$p->id] = ['enabled' => true, 'priority' => $p->pivot->priority, 'weight' => $p->pivot->weight];
        }
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'form.name' => ['required', 'string', 'max:100', Rule::unique('routing_rules', 'name')->ignore($this->editingId)],
            'form.strategy' => ['required', Rule::in(array_keys(RoutingRule::STRATEGIES))],
            'form.conditions.from_domain' => ['nullable', 'string', 'max:255'],
            'form.conditions.to_domain' => ['nullable', 'string', 'max:255'],
            'form.conditions.tag' => ['nullable', 'string', 'max:255'],
            'form.conditions.api_key_id' => ['nullable', 'integer', 'exists:api_keys,id'],
            'members.*.priority' => ['integer', 'min:0'],
            'members.*.weight' => ['integer', 'min:0'],
        ]);

        $enabled = collect($this->members)->filter(fn ($m) => ! empty($m['enabled']));
        if ($enabled->isEmpty()) {
            $this->addError('members', 'Pick at least one provider.');

            return;
        }

        $conditions = array_filter($this->form['conditions'], fn ($v) => $v !== null && $v !== '');
        if (! $this->form['is_default'] && $conditions === []) {
            $this->addError('form.conditions', 'Add at least one condition, or make this the default route.');

            return;
        }

        DB::transaction(function () use ($enabled, $conditions) {
            if ($this->form['is_default']) {
                RoutingRule::where('is_default', true)->where('id', '!=', $this->editingId ?? 0)->update(['is_default' => false]);
            }

            $attrs = [
                'name' => $this->form['name'],
                'strategy' => $this->form['strategy'],
                'is_default' => (bool) $this->form['is_default'],
                'is_active' => (bool) $this->form['is_active'],
                'conditions' => $this->form['is_default'] ? null : $conditions,
            ];

            $rule = $this->editingId
                ? tap(RoutingRule::findOrFail($this->editingId))->update($attrs)
                : RoutingRule::create($attrs + ['priority' => (int) RoutingRule::max('priority') + 1]);

            $rule->providers()->sync($enabled->mapWithKeys(fn ($m, $id) => [$id => ['priority' => (int) $m['priority'], 'weight' => (int) $m['weight']]])->all());
        });

        $this->showForm = false;
        $this->dispatch('toast', message: 'Route saved');
    }

    public function move(int $id, int $direction): void
    {
        $rules = RoutingRule::orderBy('priority')->orderBy('id')->get()->values();
        $index = $rules->search(fn ($r) => $r->id === $id);
        $swap = $index + $direction;
        if ($index === false || $swap < 0 || $swap >= $rules->count()) {
            return;
        }

        $ordered = $rules->all();
        [$ordered[$index], $ordered[$swap]] = [$ordered[$swap], $ordered[$index]];
        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $i => $rule) {
                $rule->update(['priority' => $i + 1]);
            }
        });
    }

    public function toggle(int $id): void
    {
        $rule = RoutingRule::findOrFail($id);
        $rule->update(['is_active' => ! $rule->is_active]);
    }

    public function delete(int $id): void
    {
        RoutingRule::findOrFail($id)->delete();
        $this->dispatch('toast', message: 'Route deleted');
    }

    public function simulate(EmailRouter $router): void
    {
        $this->validate(['sim.to' => 'required|string', 'sim.from' => 'required|string']);

        $tags = [];
        if ($this->sim['tag'] !== '') {
            [$name, $value] = array_pad(explode(':', $this->sim['tag'], 2), 2, '');
            $tags[] = ['name' => $name, 'value' => $value];
        }

        $email = new Email([
            'from' => $this->sim['from'],
            'to' => array_map('trim', explode(',', $this->sim['to'])),
            'tags' => $tags,
            'api_key_id' => $this->sim['api_key_id'] ?: null,
        ]);

        $rule = $router->ruleFor($email);
        $this->simResult = [
            'rule' => $rule?->name,
            'strategy' => $rule ? RoutingRule::STRATEGIES[$rule->strategy] : 'All active providers by priority',
            'providers' => $router->candidates($email, $rule)->map(fn (Provider $p) => $p->name)->all(),
        ];
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'strategy' => 'failover',
            'is_default' => RoutingRule::where('is_default', true)->doesntExist(),
            'is_active' => true,
            'conditions' => array_fill_keys(RoutingRule::CONDITION_KEYS, ''),
        ];
        $this->members = $this->blankMembers();
        $this->resetErrorBag();
    }

    private function blankMembers(): array
    {
        return Provider::orderBy('priority')->get()->mapWithKeys(fn ($p) => [
            $p->id => ['enabled' => false, 'priority' => $p->priority, 'weight' => $p->weight],
        ])->all();
    }

    public function render()
    {
        return view('livewire.routing', [
            'rules' => RoutingRule::with('providers')->orderBy('priority')->orderBy('id')->get(),
            'providers' => Provider::orderBy('priority')->get()->keyBy('id'),
            'apiKeys' => ApiKey::whereNull('revoked_at')->orderBy('name')->get(),
        ]);
    }
}
