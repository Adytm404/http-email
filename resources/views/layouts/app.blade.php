<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.head')
</head>
<body class="min-h-screen" x-data="{ nav: false }" x-on:keydown.escape.window="nav = false">
    @php
        $user = auth()->user();
        $sections = [
            null => [
                ['dashboard', 'Overview', 'home', 'view_dashboard'],
                ['emails', 'Emails', 'mail', 'view_emails'],
                ['compose', 'Send email', 'send', 'send_emails'],
            ],
            'Delivery' => [
                ['providers', 'Providers', 'server', 'manage_providers'],
                ['routing', 'Routing', 'route', 'manage_routing'],
                ['keys', 'API keys', 'key', 'manage_api_keys'],
                ['docs', 'API docs', 'book', null],
            ],
            'Administration' => [
                ['users', 'Users', 'users', 'manage_users'],
                ['roles', 'Roles', 'shield', 'manage_roles'],
                ['settings', 'Settings', 'settings', 'manage_settings'],
            ],
        ];
    @endphp

    {{-- Mobile top bar --}}
    <div class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-line bg-canvas/90 px-4 backdrop-blur lg:hidden">
        <button type="button" x-on:click="nav = true" class="grid size-9 place-items-center rounded-[4px] text-muted hover:bg-surface-2 hover:text-ink" aria-label="Open navigation">
            <x-ui.icon name="menu" />
        </button>
        <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2 text-[16px] font-bold tracking-tight">
            <x-ui.logo class="size-6" /> mailroute
        </a>
        <div class="ml-auto"><x-ui.theme-toggle /></div>
    </div>

    {{-- Backdrop for the mobile drawer --}}
    <div x-show="nav" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-slate-950/40 backdrop-blur-[2px] lg:hidden" x-on:click="nav = false"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-line bg-surface transition-transform duration-200 lg:translate-x-0"
           :class="nav && 'translate-x-0'" aria-label="Sidebar">
        <div class="flex h-16 shrink-0 items-center justify-between px-5">
            <a href="{{ route('home') }}" wire:navigate class="flex items-center gap-2 text-[17px] font-bold tracking-tight">
                <x-ui.logo class="size-7" /> mailroute
            </a>
            <button type="button" x-on:click="nav = false" class="grid size-8 place-items-center rounded-[4px] text-muted hover:bg-surface-2 lg:hidden" aria-label="Close navigation">
                <x-ui.icon name="x" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="Main">
            @foreach ($sections as $heading => $items)
                @php $visible = array_filter($items, fn ($i) => $i[3] === null || $user->can($i[3])); @endphp
                @if ($visible)
                    @if ($heading)
                        <p class="eyebrow mb-1.5 mt-6 px-3">{{ $heading }}</p>
                    @endif
                    <ul class="space-y-0.5">
                        @foreach ($visible as [$name, $label, $icon])
                            @php $active = request()->routeIs($name) || request()->routeIs($name.'.*'); @endphp
                            <li>
                                <a href="{{ route($name) }}" wire:navigate
                                   @class([
                                       'group relative flex items-center gap-3 rounded-lg px-3 py-2 text-[14px] transition-colors',
                                       'bg-accent-soft font-medium text-accent' => $active,
                                       'text-muted hover:bg-surface-2 hover:text-ink' => ! $active,
                                   ])
                                   @if ($active) aria-current="page" @endif>
                                    @if ($active)<span class="absolute -left-3 top-1/2 h-5 w-[3px] -translate-y-1/2 rounded-r-full bg-accent"></span>@endif
                                    <x-ui.icon :name="$icon" />
                                    {{ $label }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </nav>

        <div class="shrink-0 border-t border-line p-3">
            <div class="flex items-center gap-3 rounded-xl px-2 py-2">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-accent text-[13px] font-semibold text-white">{{ $user->initials() }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[13.5px] font-medium">{{ $user->name }}</p>
                    <p class="truncate text-[12px] text-muted">{{ $user->role?->name ?? 'No role' }}</p>
                </div>
                <div class="hidden lg:block"><x-ui.theme-toggle /></div>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="mt-1">
                @csrf
                <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-[13.5px] text-muted transition-colors hover:bg-surface-2 hover:text-ink">
                    <x-ui.icon name="logout" /> Sign out
                </button>
            </form>
        </div>
    </aside>

    <main class="min-w-0 px-4 pb-24 pt-6 sm:px-6 lg:ml-64 lg:px-10 lg:pt-10">
        {{ $slot }}
    </main>

    <x-ui.toast />
    @livewireScripts
</body>
</html>
