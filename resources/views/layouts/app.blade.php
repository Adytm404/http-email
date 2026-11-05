<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.head')
</head>
<body class="min-h-screen">
    @php
        $nav = [
            ['dashboard', 'Overview'],
            ['emails', 'Emails'],
            ['providers', 'Providers'],
            ['routing', 'Routing'],
            ['keys', 'API keys'],
            ['docs', 'Docs'],
            ['settings', 'Settings'],
        ];
    @endphp

    <header class="sticky top-0 z-30 border-b border-line/70 bg-canvas/85 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6">
            <a href="{{ route('dashboard') }}" wire:navigate class="flex shrink-0 items-center gap-2 text-[17px] font-bold tracking-tight">
                <x-ui.logo class="size-6" />
                mailroute
            </a>

            <nav class="-mx-1 hidden min-w-0 flex-1 items-center gap-1 overflow-x-auto md:flex" aria-label="Main">
                @foreach ($nav as [$name, $label])
                    @php $active = request()->routeIs($name) || request()->routeIs($name.'.*'); @endphp
                    <a href="{{ route($name) }}" wire:navigate
                       @class([
                           'whitespace-nowrap rounded-[4px] px-2.5 py-1.5 text-[13.5px] transition-colors',
                           'text-ink font-medium bg-surface-2' => $active,
                           'text-muted hover:text-ink' => ! $active,
                       ])
                       @if ($active) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            <div class="ml-auto flex items-center gap-2">
                <x-ui.theme-toggle />
                <form method="POST" action="{{ route('logout') }}" class="hidden sm:block">
                    @csrf
                    <button type="submit" class="rounded-[4px] px-2.5 py-1.5 text-[13.5px] text-muted hover:text-ink">Sign out</button>
                </form>
            </div>
        </div>

        {{-- Mobile nav --}}
        <nav class="flex gap-1 overflow-x-auto border-t border-line/70 px-4 py-2 [scrollbar-width:none] md:hidden [&::-webkit-scrollbar]:hidden" aria-label="Main">
            @foreach ($nav as [$name, $label])
                @php $active = request()->routeIs($name) || request()->routeIs($name.'.*'); @endphp
                <a href="{{ route($name) }}" wire:navigate
                   @class([
                       'whitespace-nowrap rounded-[4px] px-2.5 py-1.5 text-[13px]',
                       'bg-surface-2 font-medium text-ink' => $active,
                       'text-muted' => ! $active,
                   ])>{{ $label }}</a>
            @endforeach
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="whitespace-nowrap rounded-[4px] px-2.5 py-1.5 text-[13px] text-muted">Sign out</button>
            </form>
        </nav>
    </header>

    <main class="mx-auto max-w-6xl px-4 pb-24 pt-8 sm:px-6 sm:pt-10">
        {{ $slot }}
    </main>

    <x-ui.toast />
    @livewireScripts
</body>
</html>
