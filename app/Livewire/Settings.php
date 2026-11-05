<?php

namespace App\Livewire;

use App\Auth\Captcha;
use App\Models\Setting;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class Settings extends Component
{
    /** Runs on every request, including Livewire actions. */
    public function boot(): void
    {
        $this->authorize('manage_settings');
    }

    public ?string $default_from = '';

    public int $retention_days = 30;

    public int $breaker_threshold = 3;

    public int $breaker_cooldown_seconds = 300;

    public string $captcha_mode = 'off';

    public string $captcha_site_key = '';

    /** Only filled when the admin types a new secret; the stored one never reaches the browser. */
    public string $captcha_secret = '';

    public float $captcha_min_score = 0.5;

    public function mount(Captcha $captcha): void
    {
        foreach (array_keys(Setting::DEFAULTS) as $key) {
            $this->{$key} = Setting::get($key) ?? $this->{$key};
        }

        $this->captcha_mode = $captcha->mode();
        $this->captcha_site_key = $captcha->siteKey();
        $this->captcha_min_score = $captcha->minScore();
    }

    public function save(Captcha $captcha): void
    {
        $data = $this->validate([
            'default_from' => ['nullable', 'string', 'max:320'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'breaker_threshold' => ['required', 'integer', 'min:1', 'max:100'],
            'breaker_cooldown_seconds' => ['required', 'integer', 'min:10', 'max:86400'],
        ]);

        foreach ($data as $key => $value) {
            Setting::put($key, $value === '' ? null : $value);
        }

        $this->dispatch('toast', message: 'Settings saved');
    }

    public function saveCaptcha(Captcha $captcha): void
    {
        $enabling = $this->captcha_mode !== 'off';
        $needsSecret = $enabling && ! $captcha->hasSecret();

        $this->validate([
            'captcha_mode' => ['required', Rule::in(array_keys(Captcha::MODES))],
            'captcha_site_key' => [$enabling ? 'required' : 'nullable', 'string', 'max:100'],
            'captcha_secret' => [$needsSecret ? 'required' : 'nullable', 'string', 'max:100'],
            'captcha_min_score' => ['required', 'numeric', 'min:0.1', 'max:0.9'],
        ], [], [
            'captcha_site_key' => 'site key',
            'captcha_secret' => 'secret key',
            'captcha_min_score' => 'minimum score',
        ]);

        Setting::put('captcha_mode', $this->captcha_mode);
        Setting::put('captcha_site_key', trim($this->captcha_site_key) ?: null);
        Setting::put('captcha_min_score', round($this->captcha_min_score, 1));
        if (trim($this->captcha_secret) !== '') {
            Captcha::storeSecret(trim($this->captcha_secret));
        }

        $this->captcha_secret = '';
        $this->dispatch('toast', message: $enabling
            ? Captcha::MODES[$this->captcha_mode].' enabled on the sign-in page'
            : 'Sign-in captcha turned off');
    }

    public function forgetCaptchaSecret(): void
    {
        Captcha::storeSecret(null);
        Setting::put('captcha_mode', 'off');
        $this->captcha_mode = 'off';
        $this->dispatch('toast', message: 'Secret key removed and captcha turned off');
    }

    public function render(Captcha $captcha)
    {
        return view('livewire.settings', [
            'hasSecret' => $captcha->hasSecret(),
            'captchaActive' => $captcha->enabled(),
            'killSwitch' => (bool) config('mailroute.captcha_disabled'),
        ]);
    }
}
