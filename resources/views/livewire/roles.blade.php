<div>
    <x-ui.page-header eyebrow="Administration" title="Roles & permissions" description="Create roles and pick exactly what they can do. Changes apply to every user with that role right away.">
        <x-slot:actions>
            @can('manage_users')
                <x-ui.button variant="secondary" :href="route('users')"><x-ui.icon name="users" class="size-4" /> Users</x-ui.button>
            @endcan
            <x-ui.button wire:click="create"><x-ui.icon name="plus" class="size-4" /> New role</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
        @foreach ($roles as $role)
            @php $perms = $role->effectivePermissions(); @endphp
            <x-ui.card wire:key="role-{{ $role->id }}" class="flex flex-col">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h2 class="text-[16px] font-semibold">{{ $role->name }}</h2>
                            @if ($role->is_owner)<x-ui.badge tone="accent">Built-in</x-ui.badge>@endif
                        </div>
                        <p class="mt-1 text-[13px] text-muted">{{ $role->description ?: 'No description' }}</p>
                    </div>
                    <span class="shrink-0 rounded-full bg-surface-2 px-2.5 py-1 text-[12px] text-muted">{{ $role->users_count }} {{ Str::plural('user', $role->users_count) }}</span>
                </div>

                <div class="mt-4 flex items-center gap-3">
                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-surface-2">
                        <div class="h-full rounded-full bg-accent" style="width: {{ count($perms) / count(\App\Auth\Permission::ALL) * 100 }}%"></div>
                    </div>
                    <span class="text-[12px] tabular-nums text-muted">{{ count($perms) }}/{{ count(\App\Auth\Permission::ALL) }}</span>
                </div>

                <div class="mt-4 flex flex-1 flex-wrap content-start gap-1.5">
                    @foreach ($perms as $p)
                        <span class="rounded-md border border-line bg-canvas px-2 py-0.5 text-[12px]">{{ \App\Auth\Permission::label($p) }}</span>
                    @endforeach
                </div>

                <div class="mt-5 flex gap-1 border-t border-line pt-3">
                    @unless ($role->is_owner)
                        <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $role->id }})">Edit</x-ui.button>
                    @endunless
                    <x-ui.button variant="ghost" size="sm" wire:click="duplicate({{ $role->id }})">Duplicate</x-ui.button>
                    @unless ($role->is_owner)
                        <x-ui.button variant="ghost" size="sm" wire:click="delete({{ $role->id }})" wire:confirm="Delete the {{ $role->name }} role?" class="ml-auto text-bad hover:bg-bad-soft hover:text-bad">Delete</x-ui.button>
                    @endunless
                </div>
            </x-ui.card>
        @endforeach
    </div>

    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Edit role' : 'New role'" close="$set('showForm', false)" wide>
            <form id="role-form" wire:submit="save" class="space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="r-name" class="label">Name</label>
                        <input id="r-name" wire:model="name" class="field" placeholder="e.g. Support">
                        @error('name') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="r-desc" class="label">Description</label>
                        <input id="r-desc" wire:model="description" class="field" placeholder="What this role is for">
                    </div>
                </div>

                <div class="space-y-4">
                    @foreach ($groups as $group => $items)
                        @php $allOn = array_diff(array_keys($items), $permissions) === []; @endphp
                        <div wire:key="g-{{ $group }}" class="overflow-hidden rounded-xl border border-line">
                            <div class="flex items-center justify-between bg-canvas px-4 py-2.5">
                                <p class="text-[13px] font-semibold">{{ $group }}</p>
                                <button type="button" wire:click="toggleGroup('{{ $group }}')" class="text-[12.5px] text-accent hover:underline">{{ $allOn ? 'Clear all' : 'Select all' }}</button>
                            </div>
                            @foreach ($items as $key => [$label, $desc])
                                <label wire:key="p-{{ $key }}" class="flex cursor-pointer items-start gap-3 border-t border-line px-4 py-3 text-sm hover:bg-surface-2/50">
                                    <input type="checkbox" wire:model.live="permissions" value="{{ $key }}" class="mt-0.5 size-4 accent-[#1677ff]">
                                    <span><span class="font-medium">{{ $label }}</span><br><span class="text-[12.5px] text-muted">{{ $desc }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="role-form">Save role</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
