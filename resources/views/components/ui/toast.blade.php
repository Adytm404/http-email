<div x-data="{ items: [] }"
     x-on:toast.window="const id = Date.now() + Math.random(); items.push({ id, ...$event.detail }); setTimeout(() => items = items.filter(i => i.id !== id), 4000)"
     class="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-center gap-2 sm:inset-x-auto sm:right-6 sm:items-end"
     aria-live="polite">
    <template x-for="item in items" :key="item.id">
        <div class="pointer-events-auto flex max-w-sm items-start gap-2.5 rounded-xl border border-line bg-surface px-4 py-3 text-sm shadow-lg shadow-slate-900/5">
            <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="item.type === 'error' ? 'bg-bad' : 'bg-ok'"></span>
            <span x-text="item.message"></span>
        </div>
    </template>
</div>
