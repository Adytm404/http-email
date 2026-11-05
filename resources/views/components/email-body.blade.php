@props(['email', 'height' => 'h-[520px]'])

{{-- Tabbed view of an email body. The HTML preview runs in a sandboxed iframe without scripts. --}}
<div x-data="{ tab: '{{ $email->html ? 'html' : 'text' }}', copied: false }" {{ $attributes }}>
    <div class="flex items-center justify-between gap-2 border-b border-line px-3 pt-2">
        <div class="flex gap-1 overflow-x-auto [scrollbar-width:none]" role="tablist">
            @foreach (['html' => 'Preview', 'text' => 'Plain text', 'source' => 'HTML source'] as $key => $label)
                <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'"
                        class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-[13px] transition-colors"
                        :class="tab === '{{ $key }}' ? 'border-accent font-medium text-ink' : 'border-transparent text-muted hover:text-ink'">{{ $label }}</button>
            @endforeach
        </div>
        <button type="button" class="mb-1.5 inline-flex items-center gap-1.5 rounded-[4px] px-2 py-1 text-[12.5px] text-muted hover:bg-surface-2 hover:text-ink"
                x-show="tab !== 'html'" x-cloak
                x-on:click="navigator.clipboard.writeText(tab === 'text' ? @js($email->text ?? '') : @js($email->html ?? '')); copied = true; setTimeout(() => copied = false, 1500)">
            <x-ui.icon name="copy" class="size-3.5" /><span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
        </button>
    </div>
    <div x-show="tab === 'html'" class="bg-white">
        @if ($email->html)
            <iframe srcdoc="{{ $email->html }}" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" class="{{ $height }} w-full" title="Email preview"></iframe>
        @else
            <p class="p-6 text-sm text-slate-500">This email has no HTML body.</p>
        @endif
    </div>
    <pre x-show="tab === 'text'" x-cloak class="{{ $height }} overflow-auto whitespace-pre-wrap p-5 font-mono text-[12.5px] leading-relaxed">{{ $email->text ?? 'This email has no plain-text body.' }}</pre>
    <pre x-show="tab === 'source'" x-cloak class="{{ $height }} overflow-auto whitespace-pre-wrap p-5 font-mono text-[12px] leading-relaxed">{{ $email->html ?? 'This email has no HTML body.' }}</pre>
</div>
