<?php

namespace App\Livewire;

use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Users')]
class Users extends Component
{
    #[Url(except: '')]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        $this->authorize('manage_users');
        $this->resetForm();
    }

    public function create(): void
    {
        $this->authorize('manage_users');
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('manage_users');
        $user = User::findOrFail($id);
        $this->editingId = $user->id;
        $this->form = [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'is_active' => $user->is_active,
            'password' => '',
        ];
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->authorize('manage_users');
        $user = $this->editingId ? User::findOrFail($this->editingId) : null;

        $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editingId)],
            'form.role_id' => ['required', 'exists:roles,id'],
            'form.password' => [$user ? 'nullable' : 'required', Password::min(10)],
        ], [], ['form.role_id' => 'role', 'form.password' => 'password']);

        $role = Role::find($this->form['role_id']);
        $active = (bool) $this->form['is_active'];

        // Never leave the system without an active owner.
        if ($user && $user->isOwner() && (! $role->is_owner || ! $active) && User::otherActiveOwners($user->id) === 0) {
            $this->addError('form.role_id', 'This is the last active owner. Make someone else an owner first.');

            return;
        }
        if ($user && $user->is(auth()->user()) && ! $active) {
            $this->addError('form.is_active', 'You cannot deactivate your own account.');

            return;
        }

        $data = [
            'name' => $this->form['name'],
            'email' => $this->form['email'],
            'role_id' => $role->id,
            'is_active' => $active,
        ];
        if (($this->form['password'] ?? '') !== '') {
            $data['password'] = $this->form['password'];
        }

        $user ? $user->update($data) : User::create($data);

        $this->form['password'] = '';
        $this->showForm = false;
        $this->dispatch('toast', message: $user ? 'User updated' : 'User added');
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('manage_users');
        $user = User::findOrFail($id);

        if ($user->is(auth()->user())) {
            $this->dispatch('toast', type: 'error', message: 'You cannot deactivate your own account.');

            return;
        }
        if ($user->is_active && $user->isOwner() && User::otherActiveOwners($user->id) === 0) {
            $this->dispatch('toast', type: 'error', message: 'This is the last active owner.');

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);
        $this->dispatch('toast', message: $user->is_active ? 'User activated' : 'User deactivated');
    }

    public function delete(int $id): void
    {
        $this->authorize('manage_users');
        $user = User::findOrFail($id);

        if ($user->is(auth()->user())) {
            $this->dispatch('toast', type: 'error', message: 'You cannot delete your own account.');

            return;
        }
        if ($user->isOwner() && User::otherActiveOwners($user->id) === 0) {
            $this->dispatch('toast', type: 'error', message: 'This is the last active owner.');

            return;
        }

        $user->delete();
        $this->dispatch('toast', message: 'User removed');
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'name' => '',
            'email' => '',
            'role_id' => Role::where('is_owner', false)->orderBy('id')->value('id') ?? Role::owner()?->id,
            'is_active' => true,
            'password' => '',
        ];
        $this->resetErrorBag();
    }

    public function render()
    {
        $users = User::with('role')
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', '%'.trim($this->search).'%')
                ->orWhere('email', 'ilike', '%'.trim($this->search).'%')))
            ->orderByDesc('is_active')->orderBy('name')
            ->get();

        return view('livewire.users', [
            'users' => $users,
            'roles' => Role::orderByDesc('is_owner')->orderBy('name')->get(),
        ]);
    }
}
