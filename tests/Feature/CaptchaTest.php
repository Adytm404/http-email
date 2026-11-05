<?php

namespace Tests\Feature;

use App\Auth\Captcha;
use App\Livewire\Auth\Login;
use App\Livewire\Settings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY = 'https://www.google.com/recaptcha/api/siteverify';

    private function configure(string $mode, float $minScore = 0.5): void
    {
        Setting::put('captcha_mode', $mode);
        Setting::put('captcha_site_key', 'site-key-123');
        Setting::put('captcha_min_score', $minScore);
        Captcha::storeSecret('secret-xyz');
    }

    private function attempt(string $token = ''): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Login::class)
            ->set('email', 'boss@example.com')
            ->set('password', 'password')
            ->set('captchaToken', $token)
            ->call('login');
    }

    public function test_login_has_no_captcha_when_off(): void
    {
        $this->owner(['email' => 'boss@example.com']);
        Http::fake();

        Livewire::test(Login::class)->assertDontSee('recaptcha/api.js');
        $this->attempt()->assertHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_v2_requires_a_valid_token(): void
    {
        $this->owner(['email' => 'boss@example.com']);
        $this->configure('v2');
        Http::fake([self::VERIFY => Http::sequence()
            ->push(['success' => false, 'error-codes' => ['invalid-input-response']])
            ->push(['success' => true])]);

        Livewire::test(Login::class)->assertSee('render=explicit', false)->assertSee('site-key-123');

        $this->attempt()->assertHasErrors('captcha');
        $this->assertGuest();

        $this->attempt('bad-token')->assertHasErrors('captcha')->assertDispatched('captcha-reset');
        $this->assertGuest();

        $this->attempt('good-token')->assertHasNoErrors();
        $this->assertAuthenticated();

        Http::assertSent(fn ($r) => $r['secret'] === 'secret-xyz' && $r['response'] === 'good-token');
    }

    public function test_v3_checks_score_and_action(): void
    {
        $this->owner(['email' => 'boss@example.com']);
        $this->configure('v3', 0.7);
        Http::fake([self::VERIFY => Http::sequence()
            ->push(['success' => true, 'score' => 0.3, 'action' => 'login'])
            ->push(['success' => true, 'score' => 0.9, 'action' => 'signup'])
            ->push(['success' => true, 'score' => 0.9, 'action' => 'login'])]);

        Livewire::test(Login::class)->assertSee('render=site-key-123', false);

        $this->attempt('t1')->assertHasErrors('captcha');
        $this->attempt('t2')->assertHasErrors('captcha');
        $this->attempt('t3')->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_google_unreachable_fails_closed(): void
    {
        $this->owner(['email' => 'boss@example.com']);
        $this->configure('v2');
        Http::fake([self::VERIFY => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $this->attempt('token')->assertHasErrors('captcha');
        $this->assertGuest();
    }

    public function test_kill_switch_bypasses_captcha(): void
    {
        $this->owner(['email' => 'boss@example.com']);
        $this->configure('v2');
        config(['mailroute.captcha_disabled' => true]);
        Http::fake();

        $this->attempt()->assertHasNoErrors();
        Http::assertNothingSent();
    }

    public function test_settings_store_secret_encrypted_and_never_expose_it(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(Settings::class)
            ->set('captcha_mode', 'v3')
            ->set('captcha_site_key', 'site-abc')
            ->call('saveCaptcha')
            ->assertHasErrors('captcha_secret');

        Livewire::test(Settings::class)
            ->set('captcha_mode', 'v3')
            ->set('captcha_site_key', 'site-abc')
            ->set('captcha_secret', 'my-secret')
            ->set('captcha_min_score', 0.6)
            ->call('saveCaptcha')
            ->assertHasNoErrors()
            ->assertSet('captcha_secret', '');

        $this->assertNotSame('my-secret', Setting::where('key', 'captcha_secret')->value('value'));
        $this->assertSame('my-secret', app(Captcha::class)->secret());
        $this->assertTrue(app(Captcha::class)->enabled());

        // Re-opening the page keeps the secret off the wire, and saving with it blank keeps it.
        Livewire::test(Settings::class)
            ->assertSet('captcha_secret', '')
            ->assertDontSee('my-secret')
            ->set('captcha_mode', 'v2')
            ->call('saveCaptcha')
            ->assertHasNoErrors();
        $this->assertSame('my-secret', app(Captcha::class)->secret());
        $this->assertSame('v2', app(Captcha::class)->mode());

        $this->artisan('app:captcha-off')->assertSuccessful();
        $this->assertFalse(app(Captcha::class)->enabled());
    }
}
