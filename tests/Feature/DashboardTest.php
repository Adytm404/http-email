<?php

namespace Tests\Feature;

use App\Livewire\ApiKeys;
use App\Livewire\Auth\Login;
use App\Livewire\Providers;
use App\Livewire\Routing;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Provider;
use App\Models\RoutingRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_first_run_creates_admin_then_setup_is_closed(): void
    {
        Livewire::test(Login::class)
            ->assertSet('setup', true)
            ->set('name', 'Admin')->set('email', 'admin@example.com')->set('password', 'secret-password')
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::count());
        Livewire::test(Login::class)->assertSet('setup', false);
    }

    public function test_every_page_renders(): void
    {
        $this->actingAs(User::factory()->create());
        $this->provider('A');
        $email = Email::create(['from' => 'a@b.test', 'to' => ['c@d.test'], 'subject' => 'Hello', 'html' => '<p>x</p>', 'status' => 'sent']);

        foreach (['/dashboard', '/dashboard/emails', '/dashboard/providers', '/dashboard/routing', '/dashboard/keys', '/dashboard/docs', '/dashboard/settings', "/dashboard/emails/{$email->id}"] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_provider_form_keeps_secret_when_left_blank(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Providers::class)
            ->call('create')
            ->set('form.type', 'resend')
            ->set('form.name', 'Resend main')
            ->set('config.api_key', 're_secret')
            ->call('save')
            ->assertHasNoErrors();

        $p = Provider::where('name', 'Resend main')->first();

        Livewire::test(Providers::class)
            ->call('edit', $p->id)
            ->assertSet('config.api_key', '')
            ->set('form.name', 'Resend renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('re_secret', $p->fresh()->config['api_key']);
        $this->assertSame('Resend renamed', $p->fresh()->name);
    }

    public function test_route_form_and_simulator(): void
    {
        $this->actingAs(User::factory()->create());
        $a = $this->provider('A');
        $b = $this->provider('B');

        Livewire::test(Routing::class)
            ->call('create')
            ->set('form.name', 'Gmail')
            ->set('form.is_default', false)
            ->set('form.conditions.to_domain', 'gmail.com')
            ->set("members.{$b->id}.enabled", true)
            ->call('save')
            ->assertHasNoErrors()
            ->set('sim.from', 'x@acme.test')
            ->set('sim.to', 'y@gmail.com')
            ->call('simulate')
            ->assertSet('simResult.rule', 'Gmail')
            ->assertSet('simResult.providers', ['B']);

        $this->assertSame(['to_domain' => 'gmail.com'], RoutingRule::first()->conditions);
    }

    public function test_api_key_token_is_shown_once_and_stored_hashed(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test(ApiKeys::class)->call('create')->set('name', 'App')->call('save');
        $token = $component->get('newToken');

        $this->assertStringStartsWith('re_', $token);
        $this->assertNotNull(ApiKey::findByToken($token));
        $this->assertDatabaseMissing('api_keys', ['token_hash' => $token]);
    }
}
