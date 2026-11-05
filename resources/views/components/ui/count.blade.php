@props(['value', 'decimals' => 0, 'suffix' => ''])

{{-- Counts up from 0 when it first appears (or when the value changes, via wire:key). --}}
<span wire:key="count-{{ md5($attributes->get('id', '').$value) }}"
      x-data="{ shown: 0, target: {{ (float) $value }} }"
      x-init="
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { shown = target; return }
        const start = performance.now(), dur = 900;
        const tick = (now) => { const p = Math.min(1, (now - start) / dur); shown = target * (1 - Math.pow(1 - p, 3)); if (p < 1) requestAnimationFrame(tick) };
        requestAnimationFrame(tick)"
      x-text="shown.toLocaleString(undefined, { minimumFractionDigits: {{ $decimals }}, maximumFractionDigits: {{ $decimals }} }) + @js($suffix)"
      {{ $attributes->except('id') }}>{{ number_format((float) $value, $decimals) }}{{ $suffix }}</span>
