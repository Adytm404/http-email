<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='7' fill='%231677ff'/><path d='M8 11.5 16 17l8-5.5M8 10h16v12H8z' fill='none' stroke='white' stroke-width='2' stroke-linejoin='round'/></svg>">
<script>
    (() => {
        let theme = null;
        try { theme = localStorage.getItem('theme'); } catch (e) {}
        const dark = theme ? theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.classList.toggle('dark', dark);
    })();
</script>
@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])
@livewireStyles
