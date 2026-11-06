@props(['status'])

@php
    $tone = match ($status) {
        'sent', 'healthy' => 'ok',
        'failed', 'down' => 'bad',
        'queued', 'sending', 'scheduled' => 'accent',
        'degraded', 'limited' => 'warn',
        default => 'neutral',
    };
@endphp

<x-ui.badge :tone="$tone" dot {{ $attributes }}>{{ ucfirst($status) }}</x-ui.badge>
