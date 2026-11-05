<div>
    <x-ui.page-header eyebrow="Administration" title="Users" description="People who can sign in to this dashboard. What each person can do comes from their role.">
        <x-slot:actions>
            @can('manage_roles')
                <x-ui.button variant="secondary" :href="route('roles')"><x-ui.icon name="shield" class="size-4" /> Roles</x-ui.button>
            @endcan
            <x-ui.button wire:click="create"><x-ui.icon name="plus" class="size-4" /> Add user</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4 sm:w-80">
        <div class="relative">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
            <input wire:model.live.debounce.300ms="search" type="search" class="field pl-9" placeholder="Search name or email" aria-label="Search users">
        </div>
    </div>

    <x-ui.card :padding="false" class="relative overflow-x-auto">
        <table class="w-full min-w-[720px] text-sm">
            <thead>
                <tr class="text-left text-[12px] text-muted">
                    <th class="px-6 py-3 font-medium">User</th>
                    <th class="px-3 py-3 font-medium">Role</th>
                    <th class="px-3 py-3 font-medium">Status</th>
                    <th class="px-3 py-3 font-medium">Last sign-in</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $u)
                    <tr wire:key="u-{{ $u->id }}" @class(['border-t border-line', 'opacity-60' => ! $u->is_active])>
                        <td class="px-6 py-3">
                            <div class="flex items-center gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent-soft text-[13px] font-semibold text-accent">{{ $u->initials() }}</span>
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ $u->name }} @if ($u->is(auth()->user()))<span class="text-[12px] font-normal text-muted">(you)</span>@endif</p>
                                    <p class="truncate text-[12.5px] text-muted">{{ $u->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3">
                            @if ($u->role)
                                <x-ui.badge :tone="$u->role->is_owner ? 'accent' : 'neutral'">{{ $u->role->name }}</x-ui.badge>
                            @else
                                <x-ui.badge tone="warn">No role</x-ui.badge>
                            @endif
                        </td>
                        <td class="px-3 py-3"><x-ui.badge :tone="$u->is_active ? 'ok' : 'neutral'" dot>{{ $u->is_active ? 'Active' : 'Deactivated' }}</x-ui.badge></td>
                        <td class="px-3 py-3 text-muted">{{ $u->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                        <td class="px-6 py-3">
                            <div class="flex justify-end gap-1">
                                <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $u->id }})">Edit</x-ui.button>
                                @unless ($u->is(auth()->user()))
                                    <x-ui.button variant="ghost" size="sm" wire:click="toggleActive({{ $u->id }})">{{ $u->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" wire:click="delete({{ $u->id }})" wire:confirm="Remove {{ $u->name }}? They will lose access immediately." class="text-bad hover:bg-bad-soft hover:text-bad">Remove</x-ui.button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-ui.empty title="No users found" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Edit user' : 'Add user'" close="$set('showForm', false)">
            <form id="user-form" wire:submit="save" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="u-name" class="label">Name</label>
                        <input id="u-name" wire:model="form.name" class="field" autocomplete="off">
                        @error('form.name') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="u-email" class="label">Email</label>
                        <input id="u-email" wire:model="form.email" type="email" class="field" autocomplete="off">
                        @error('form.email') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <span class="label">Role</span>
                    <div class="space-y-2">
                        @foreach ($roles as $r)
                            <label wire:key="ro-{{ $r->id }}" @class(['flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm transition-colors', 'border-accent bg-accent-soft' => (int) $form['role_id'] === $r->id, 'border-line hover:bg-surface-2' => (int) $form['role_id'] !== $r->id])>
                                <input type="radio" wire:model.live="form.role_id" value="{{ $r->id }}" class="mt-0.5 accent-[#1677ff]">
                                <span class="min-w-0">
                                    <span class="font-medium">{{ $r->name }}</span>
                                    <span class="ml-1 text-[12px] text-muted">{{ $r->is_owner ? 'all permissions' : count($r->effectivePermissions()).' permissions' }}</span>
                                    @if ($r->description)<br><span class="text-[12.5px] text-muted">{{ $r->description }}</span>@endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('form.role_id') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="u-password" class="label">{{ $editingId ? 'New password' : 'Password' }}</label>
                    <input id="u-password" wire:model="form.password" type="password" class="field" autocomplete="new-password" placeholder="{{ $editingId ? 'Leave blank to keep current' : 'At least 10 characters' }}">
                    @error('form.password') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="form.is_active" class="size-4 accent-[#1677ff]"> Active (can sign in)
                </label>
                @error('form.is_active') <p class="text-[13px] text-bad">{{ $message }}</p> @enderror
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="user-form">{{ $editingId ? 'Save changes' : 'Add user' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
