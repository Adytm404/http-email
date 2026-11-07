<button type="button"
        x-data="{ dark: window.mailroute.theme.isDark() }"
        x-on:theme-changed.window="dark = $event.detail.dark"
        x-on:click="window.mailroute.theme.set(!dark)"
        class="grid size-9 place-items-center rounded-[4px] text-muted transition-colors hover:bg-surface-2 hover:text-ink"
        :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'">
    <svg x-show="!dark" class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
    <svg x-show="dark" x-cloak class="size-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
