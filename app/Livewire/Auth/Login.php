<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
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

    public bool $setup = false;

    public function mount(): void
    {
        $this->setup = User::count() === 0;
    }

    public function login()
    {
        if ($this->setup) {
            return $this->createAdmin();
        }

        $this->validate(['email' => 'required|email', 'password' => 'required']);

        $throttle = 'login:'.strtolower($this->email).'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttle).' seconds.']);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttle, 60);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        RateLimiter::clear($throttle);
        session()->regenerate();

        return $this->redirectIntended(route('dashboard'), navigate: true);
    }

    private function createAdmin()
    {
        // Only possible while no user exists yet.
        abort_if(User::count() > 0, 403);

        $this->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:10',
        ]);

        $user = User::create(['name' => $this->name, 'email' => $this->email, 'password' => $this->password]);
        Auth::login($user, true);
        session()->regenerate();

        return $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
