@props(['type'])

@php
    [$letter, $bg, $fg] = match ($type) {
        'resend' => ['R', '#000000', '#ffffff'],
        'sendgrid' => ['S', '#1a82e2', '#ffffff'],
        'mailgun' => ['M', '#f06b66', '#ffffff'],
        default => ['@', 'var(--c-surface-2)', 'var(--c-ink)'],
    };
@endphp

<span {{ $attributes->class('grid size-7 shrink-0 place-items-center rounded-md border border-line text-[12px] font-semibold') }}
      style="background: {{ $bg }}; color: {{ $fg }}" aria-hidden="true">{{ $letter }}</span>
