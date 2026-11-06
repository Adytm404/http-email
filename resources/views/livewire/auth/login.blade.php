<div class="grid grid-cols-1 min-h-screen lg:grid-cols-[1.1fr_1fr]">
    {{-- Brand panel --}}
    <div class="relative hidden p-3 lg:block">
        <div class="panel-gradient relative flex h-full flex-col justify-between overflow-hidden rounded-[28px] p-12">
            <div class="flex items-center gap-2 text-lg font-bold">
                <svg class="size-7" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="#fff" fill-opacity=".18"/><path d="M8 11.5 16 17l8-5.5M8 10h16v12H8z" fill="none" stroke="#fff" stroke-width="2" stroke-linejoin="round"/></svg>
                mailroute
            </div>

            <div>
                <h1 class="text-[64px] font-light uppercase leading-[0.95] tracking-[-0.02em] xl:text-[76px]">
                    One API<br>for every<br>mail server.
                </h1>
                <p class="mt-6 max-w-md text-[15px] leading-relaxed text-white/80">
                    Send through your own SMTP servers, Resend, SendGrid and Mailgun from a single endpoint, with routing, failover and per-provider limits.
                </p>
            </div>

            <div class="grid grid-cols-3 gap-3">
                <div class="glass rounded-2xl p-4">
                    <p class="text-[12px] text-white/70">Endpoint</p>
                    <p class="mt-6 font-mono text-[15px]">POST /emails</p>
                </div>
                <div class="glass rounded-2xl p-4">
                    <p class="text-[12px] text-white/70">Upstreams</p>
                    <p class="mt-6 text-[26px] font-light leading-none">4 types</p>
                </div>
                <div class="glass rounded-2xl p-4">
                    <p class="text-[12px] text-white/70">Code to rewrite</p>
                    <p class="mt-6 text-[26px] font-light leading-none">1 line</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Form --}}
    <div class="flex flex-col px-4 py-8 sm:px-10">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2 text-[17px] font-bold lg:invisible">
                <x-ui.logo class="size-6" /> mailroute
            </div>
            <x-ui.theme-toggle />
        </div>

        <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-12">
            <p class="eyebrow mb-3">{{ $setup ? 'First run' : 'Welcome back' }}</p>
            <h2 class="text-[30px] font-semibold tracking-tight">{{ $setup ? 'Create the admin account' : 'Sign in to the dashboard' }}</h2>
            <p class="mt-2 text-[15px] text-muted">
                {{ $setup ? 'No users exist yet. This account manages providers, routes and API keys.' : 'Manage providers, routes and API keys.' }}
            </p>

            @if ($setupLocked)
                <div class="mt-6 rounded-xl border border-line bg-surface-2 p-4 text-[13px] leading-relaxed text-muted">
                    No admin account exists yet. Open the setup link that contains your <code class="font-mono text-ink">MAILROUTE_SETUP_TOKEN</code>,
                    or run <code class="font-mono text-ink">php artisan app:create-admin</code> on the server.
                </div>
            @endif

            @if ($captchaMode === 'v3')
                {{-- v3: get a fresh token right before each sign-in, then submit. --}}
                <form class="mt-8 space-y-4"
                      x-data
                      x-on:submit.prevent="
                        if (! window.grecaptcha || ! window.grecaptcha.execute) { $wire.login(); return }
                        grecaptcha.ready(() => grecaptcha.execute(@js($captchaSiteKey), { action: 'login' })
                            .then(token => { $wire.captchaToken = token; $wire.login() })
                            .catch(() => $wire.login()))">
            @else
                <form wire:submit="login" class="mt-8 space-y-4">
            @endif
                @if ($setup)
                    <div>
                        <label for="name" class="label">Name</label>
                        <input wire:model="name" id="name" type="text" class="field" autocomplete="name" required>
                        @error('name') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label for="email" class="label">Email</label>
                    <input wire:model="email" id="email" type="email" class="field" autocomplete="email" required autofocus>
                    @error('email') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="label">Password</label>
                    <input wire:model="password" id="password" type="password" class="field" autocomplete="{{ $setup ? 'new-password' : 'current-password' }}" required>
                    @if ($setup)<p class="mt-1.5 text-[12px] text-muted">At least 10 characters.</p>@endif
                    @error('password') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>

                @unless ($setup)
                    <label class="flex items-center gap-2 text-sm text-muted">
                        <input wire:model="remember" type="checkbox" class="size-4 rounded border-line accent-[#1677ff]"> Keep me signed in
                    </label>
                @endunless

                @if ($captchaMode === 'v2')
                    <div wire:ignore
                         x-data="{
                            widget: null,
                            init() {
                                const render = () => {
                                    this.widget = grecaptcha.render(this.$refs.box, {
                                        sitekey: @js($captchaSiteKey),
                                        theme: document.documentElement.classList.contains('dark') ? 'dark' : 'light',
                                        callback: (token) => { this.$wire.captchaToken = token },
                                        'expired-callback': () => { this.$wire.captchaToken = '' },
                                    })
                                }
                                if (window.grecaptcha && window.grecaptcha.render) { render() } else { window.mrCaptchaRender = render }
                            },
                         }"
                         x-on:captcha-reset.window="if (widget !== null) grecaptcha.reset(widget)">
                        <div x-ref="box" class="min-h-[78px]"></div>
                    </div>
                @endif
                @error('captcha') <p class="text-[13px] text-bad">{{ $message }}</p> @enderror

                <x-ui.button type="submit" size="lg" class="w-full" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="login">{{ $setup ? 'Create account' : 'Sign in' }}</span>
                    <span wire:loading wire:target="login">Please wait…</span>
                </x-ui.button>

                @if ($captchaMode === 'v3')
                    <p class="text-center text-[11.5px] leading-relaxed text-subtle">
                        Protected by reCAPTCHA. Google's
                        <a href="https://policies.google.com/privacy" target="_blank" rel="noopener noreferrer" class="underline">Privacy Policy</a> and
                        <a href="https://policies.google.com/terms" target="_blank" rel="noopener noreferrer" class="underline">Terms of Service</a> apply.
                    </p>
                @endif
            </form>

            @if ($captchaMode === 'v2')
                <script>window.mrCaptchaOnload = () => window.mrCaptchaRender && window.mrCaptchaRender()</script>
                <script src="https://www.google.com/recaptcha/api.js?onload=mrCaptchaOnload&render=explicit" async defer></script>
            @elseif ($captchaMode === 'v3')
                <script src="https://www.google.com/recaptcha/api.js?render={{ urlencode($captchaSiteKey) }}" async defer></script>
            @endif
        </div>
    </div>
</div>
