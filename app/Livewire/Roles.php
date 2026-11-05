<?php

namespace App\Livewire;

use App\Auth\Permission;
use App\Models\Role;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Roles')]
class Roles extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    /** @var list<string> */
    public array $permissions = [];

    public function mount(): void
    {
        $this->authorize('manage_roles');
    }

    public function create(): void
    {
        $this->authorize('manage_roles');
        $this->reset('editingId', 'name', 'description');
        $this->permissions = ['view_dashboard', 'view_emails'];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage_roles');
        $role = Role::findOrFail($id);
        abort_if($role->is_owner, 403);

        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->permissions = $role->effectivePermissions();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function duplicate(int $id): void
    {
        $this->authorize('manage_roles');
        $role = Role::findOrFail($id);

        $this->reset('editingId');
        $this->name = $role->name.' copy';
        $this->description = (string) $role->description;
        $this->permissions = $role->effectivePermissions();
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function toggleGroup(string $group): void
    {
        $keys = array_keys(Permission::grouped()[$group] ?? []);
        $allOn = array_diff($keys, $this->permissions) === [];
        $this->permissions = $allOn
            ? array_values(array_diff($this->permissions, $keys))
            : array_values(array_unique(array_merge($this->permissions, $keys)));
    }

    public function save(): void
    {
        $this->authorize('manage_roles');

        $this->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(Permission::keys())],
        ]);

        $data = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'permissions' => array_values(array_intersect(Permission::keys(), $this->permissions)),
        ];

        if ($this->editingId) {
            $role = Role::findOrFail($this->editingId);
            abort_if($role->is_owner, 403);
            $role->update($data);
        } else {
            Role::create($data);
        }

        $this->showForm = false;
        $this->dispatch('toast', message: 'Role saved');
    }

    public function delete(int $id): void
    {
        $this->authorize('manage_roles');
        $role = Role::withCount('users')->findOrFail($id);

        if ($role->is_owner) {
            $this->dispatch('toast', type: 'error', message: 'The owner role cannot be deleted.');

            return;
        }
        if ($role->users_count > 0) {
            $this->dispatch('toast', type: 'error', message: "Move the {$role->users_count} user(s) with this role to another role first.");

            return;
        }

        $role->delete();
        $this->dispatch('toast', message: 'Role deleted');
    }

    public function render()
    {
        return view('livewire.roles', [
            'roles' => Role::withCount('users')->orderByDesc('is_owner')->orderBy('name')->get(),
            'groups' => Permission::grouped(),
        ]);
    }
}
