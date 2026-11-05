<?php

namespace App\Auth;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Google reCAPTCHA for the dashboard sign-in form.
 *
 * Mode "v2" shows the "I'm not a robot" checkbox; mode "v3" is invisible and
 * returns a score (0.0 bot … 1.0 human) that must reach the configured minimum.
 * Set MAILROUTE_CAPTCHA_DISABLED=true (or run `php artisan app:captcha-off`)
 * to get back in if the keys are wrong.
 */
class Captcha
{
    public const MODES = ['off' => 'Off', 'v2' => 'reCAPTCHA v2', 'v3' => 'reCAPTCHA v3'];

    public const ACTION = 'login';

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    public function mode(): string
    {
        $mode = (string) Setting::get('captcha_mode');

        return array_key_exists($mode, self::MODES) ? $mode : 'off';
    }

    public function enabled(): bool
    {
        return ! config('mailroute.captcha_disabled')
            && $this->mode() !== 'off'
            && $this->siteKey() !== ''
            && $this->secret() !== '';
    }

    public function siteKey(): string
    {
        return (string) Setting::get('captcha_site_key');
    }

    public function minScore(): float
    {
        return (float) (Setting::get('captcha_min_score') ?? 0.5);
    }

    public function secret(): string
    {
        $stored = (string) Setting::get('captcha_secret');
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return '';
        }
    }

    public function hasSecret(): bool
    {
        return $this->secret() !== '';
    }

    public static function storeSecret(?string $secret): void
    {
        Setting::put('captcha_secret', $secret === null || $secret === '' ? null : Crypt::encryptString($secret));
    }

    /**
     * @return string|null an error message for the user, or null when the check passed
     */
    public function check(?string $token, ?string $ip): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($token === null || $token === '') {
            return $this->mode() === 'v2'
                ? 'Please tick "I\'m not a robot" first.'
                : 'Security check did not load. Refresh the page and try again.';
        }

        try {
            $result = Http::asForm()->timeout(10)->post(self::VERIFY_URL, array_filter([
                'secret' => $this->secret(),
                'response' => $token,
                'remoteip' => $ip,
            ]))->json() ?? [];
        } catch (Throwable) {
            // Fail closed: without a verdict from Google we cannot tell humans from bots.
            return 'Could not reach the security check service. Try again in a moment.';
        }

        if (! ($result['success'] ?? false)) {
            return 'Security check failed. Please try again.';
        }

        if ($this->mode() === 'v3') {
            if (($result['action'] ?? self::ACTION) !== self::ACTION) {
                return 'Security check failed. Please try again.';
            }
            if ((float) ($result['score'] ?? 0) < $this->minScore()) {
                return 'Our security check flagged this sign-in as automated. Please try again.';
            }
        }

        return null;
    }
}
