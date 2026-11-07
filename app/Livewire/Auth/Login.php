<?php

namespace App\Livewire\Auth;

use App\Auth\Captcha;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Sign in')]
class Login extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    /** Response token from the reCAPTCHA widget (v2) or grecaptcha.execute (v3). */
    public string $captchaToken = '';

    /** First-run admin creation is allowed for this visitor. Locked so the browser cannot flip it. */
    #[Locked]
    public bool $setup = false;

    /** No user exists yet, but this visitor lacks the setup token. */
    #[Locked]
    public bool $setupLocked = false;

    public function mount(): void
    {
        if (User::count() > 0) {
            return;
        }

        // The first admin needs ?setup=<MAILROUTE_SETUP_TOKEN> (or php artisan app:create-admin),
        // whatever APP_ENV says, so a fresh deploy is never open to strangers. The only exception is
        // a local/testing environment reached directly from this machine, with no token configured.
        $token = (string) config('mailroute.setup_token');
        $allowed = $token !== ''
            ? hash_equals($token, (string) request()->query('setup'))
            : $this->isDirectLocalVisit();

        $this->setup = $allowed;
        $this->setupLocked = ! $allowed;
    }

    /** Local/testing environment, and the request comes straight from this machine (no proxy in between). */
    private function isDirectLocalVisit(): bool
    {
        return app()->environment(['local', 'testing'])
            && in_array(request()->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true)
            && ! request()->headers->has('X-Forwarded-For')
            && ! request()->headers->has('Forwarded');
    }

    public function login()
    {
        if ($this->setup) {
            return $this->createAdmin();
        }

        $this->validate(['email' => 'required|email', 'password' => 'required']);

        // After too many wrong passwords the account+IP (and, more loosely, the IP alone)
        // is locked out for the whole lockout period, counted from the first failure.
        $maxAttempts = (int) config('mailroute.login_max_attempts');
        $lockout = (int) config('mailroute.login_lockout_minutes') * 60;
        $accountKey = 'login:'.strtolower($this->email).'|'.request()->ip();
        $ipKey = 'login-ip:'.request()->ip();

        foreach ([$accountKey => $maxAttempts, $ipKey => $maxAttempts * 4] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages(['email' => "Too many failed sign-in attempts. Try again in {$minutes} ".str('minute')->plural($minutes).'.']);
            }
        }

        // reCAPTCHA tokens are single use, so the widget is reset after every attempt.
        $token = $this->captchaToken;
        $this->captchaToken = '';
        if ($error = app(Captcha::class)->check($token, request()->ip())) {
            $this->dispatch('captcha-reset');
            throw ValidationException::withMessages(['captcha' => $error]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            $this->dispatch('captcha-reset');
            RateLimiter::hit($accountKey, $lockout);
            RateLimiter::hit($ipKey, $lockout);

            $left = $maxAttempts - RateLimiter::attempts($accountKey);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.'
                .($left > 0 && $left <= 2 ? " {$left} ".str('attempt')->plural($left).' left before sign-in is locked for '.($lockout / 60).' minutes.' : '')]);
        }

        RateLimiter::clear($accountKey);
        session()->regenerate();
        Auth::user()->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->redirectIntended(route('home'), navigate: true);
    }

    private function createAdmin()
    {
        // Only possible while no user exists yet, and only for a visitor mount() allowed.
        abort_if(! $this->setup || User::count() > 0, 403);

        $this->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:10',
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
            'role_id' => Role::owner()?->id,
            'last_login_at' => now(),
        ]);
        Auth::login($user, true);
        session()->regenerate();

        return $this->redirect(route('home'), navigate: true);
    }

    public function render(Captcha $captcha)
    {
        $active = ! $this->setup && $captcha->enabled();

        return view('livewire.auth.login', [
            'captchaMode' => $active ? $captcha->mode() : 'off',
            'captchaSiteKey' => $active ? $captcha->siteKey() : null,
        ]);
    }
}
