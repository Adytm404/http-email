<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%231677ff'/><path d='M8 11.5 16 17l8-5.5M8 10h16v12H8z' fill='none' stroke='white' stroke-width='2' stroke-linejoin='round'/></svg>">
<script>
    // One theme store for every page. The choice is saved only when the user changes it;
    // until then the operating system preference decides. Re-applied after SPA navigation,
    // when a page comes back from the back/forward cache and when another tab changes it.
    (() => {
        const KEY = 'theme';
        let memory = null; // used when localStorage is blocked
        const stored = () => { try { return localStorage.getItem(KEY); } catch (e) { return memory; } };
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const isDark = () => { const t = stored(); return t === 'dark' || t === 'light' ? t === 'dark' : mq.matches; };
        const paint = () => {
            const dark = isDark();
            document.documentElement.classList.toggle('dark', dark);
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark } }));
        };
        window.mailroute = window.mailroute || {};
        window.mailroute.theme = {
            isDark,
            set(dark) {
                memory = dark ? 'dark' : 'light';
                try { localStorage.setItem(KEY, memory); } catch (e) {}
                paint();
            },
        };
        paint();
        if (!window.__themeBound) {
            window.__themeBound = true;
            document.addEventListener('livewire:navigated', paint);
            window.addEventListener('pageshow', paint);
            window.addEventListener('storage', (e) => { if (e.key === KEY || e.key === null) paint(); });
            mq.addEventListener && mq.addEventListener('change', () => { if (!stored()) paint(); });
        }
    })();
</script>
@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
