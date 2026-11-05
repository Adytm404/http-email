<?php

namespace App\Livewire\Auth;

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

        // In production the first admin can only be created with ?setup=<MAILROUTE_SETUP_TOKEN>
        // (or with `php artisan app:create-admin`), so a fresh deploy is not open to strangers.
        $token = (string) config('mailroute.setup_token');
        $allowed = $token !== ''
            ? hash_equals($token, (string) request()->query('setup'))
            : ! app()->isProduction();

        $this->setup = $allowed;
        $this->setupLocked = ! $allowed;
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

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password, 'is_active' => true], $this->remember)) {
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

    public function render()
    {
        return view('livewire.auth.login');
    }
}
