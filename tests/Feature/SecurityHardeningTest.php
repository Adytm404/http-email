<?php

namespace Tests\Feature;

use App\Livewire\Auth\Login;
use App\Livewire\Providers;
use App\Models\Email;
use App\Models\Provider;
use App\Support\SafeFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ---- first-admin setup is never open by default ----

    public function test_setup_is_locked_for_a_remote_visitor_even_in_a_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/login')
            ->assertOk()->assertSee('Sign in to the dashboard')->assertDontSee('Create the admin account');
    }

    public function test_setup_is_locked_behind_a_proxy_in_a_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->withHeaders(['X-Forwarded-For' => '203.0.113.9'])->get('/login')
            ->assertOk()->assertDontSee('Create the admin account');
    }

    public function test_setup_is_open_for_a_direct_local_visit_in_a_local_environment(): void
    {
        $this->app['env'] = 'local';

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get('/login')->assertOk()->assertSee('Create the admin account');
    }

    public function test_setup_is_locked_in_production_without_a_token(): void
    {
        $this->app['env'] = 'production';
        request()->server->set('REMOTE_ADDR', '127.0.0.1');

        Livewire::test(Login::class)->assertSet('setup', false)->assertSet('setupLocked', true);
    }

    // ---- a stored SMTP password is not reusable for another destination ----

    private function smtpProvider(): Provider
    {
        return Provider::create([
            'name' => 'SMTP',
            'type' => 'smtp',
            'config' => ['host' => 'smtp.real.test', 'port' => 587, 'encryption' => 'starttls', 'username' => 'user', 'password' => 'stored-secret', 'timeout' => 20],
        ]);
    }

    public function test_changing_the_smtp_host_requires_the_password_again(): void
    {
        $this->actingAs($this->owner());
        $p = $this->smtpProvider();

        Livewire::test(Providers::class)
            ->call('edit', $p->id)
            ->set('config.host', 'evil.test')
            ->call('save')
            ->assertHasErrors(['config.password']);

        $fresh = $p->fresh();
        $this->assertSame('smtp.real.test', $fresh->config['host']);
        $this->assertSame('stored-secret', $fresh->config['password']);
    }

    public function test_changing_username_or_encryption_also_requires_the_password(): void
    {
        $this->actingAs($this->owner());
        $p = $this->smtpProvider();

        Livewire::test(Providers::class)->call('edit', $p->id)->set('config.username', 'someone-else')->call('save')->assertHasErrors(['config.password']);
        Livewire::test(Providers::class)->call('edit', $p->id)->set('config.encryption', 'none')->call('save')->assertHasErrors(['config.password']);
    }

    public function test_changing_host_with_the_password_re_entered_is_allowed(): void
    {
        $this->actingAs($this->owner());
        $p = $this->smtpProvider();

        Livewire::test(Providers::class)
            ->call('edit', $p->id)
            ->set('config.host', 'smtp.new.test')
            ->set('config.password', 'new-secret')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('smtp.new.test', $p->fresh()->config['host']);
        $this->assertSame('new-secret', $p->fresh()->config['password']);
    }

    public function test_unrelated_edits_keep_the_stored_password(): void
    {
        $this->actingAs($this->owner());
        $p = $this->smtpProvider();

        Livewire::test(Providers::class)
            ->call('edit', $p->id)
            ->set('form.name', 'Renamed')
            ->set('form.priority', 5)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('stored-secret', $p->fresh()->config['password']);
        $this->assertSame('Renamed', $p->fresh()->name);
    }

    public function test_the_provider_type_cannot_be_changed_when_editing(): void
    {
        $this->actingAs($this->owner());
        $p = $this->smtpProvider();

        Livewire::test(Providers::class)
            ->call('edit', $p->id)
            ->set('form.type', 'resend')
            ->set('config.api_key', 're_x')
            ->call('save')
            ->assertHasErrors(['form.type']);

        $this->assertSame('smtp', $p->fresh()->type);
    }

    // ---- attachment URLs cannot reach private infrastructure ----

    private function publicDns(): void
    {
        $this->app->bind(SafeFetcher::class, fn () => new SafeFetcher(fn (string $host) => ['93.184.216.34']));
    }

    private function send(array $attachment)
    {
        [, $token] = $this->apiKey('sending_access');
        $this->provider('Primary');

        return $this->withToken($token)->postJson('/emails', [
            'from' => 'Acme <hello@acme.test>', 'to' => 'user@example.com', 'subject' => 'Hi', 'html' => '<p>x</p>',
            'attachments' => [$attachment],
        ]);
    }

    public function test_address_classification(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '172.16.9.9', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '198.18.0.1', '::1', 'fe80::1', 'fd00::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', 'not-an-ip'] as $ip) {
            $this->assertFalse(SafeFetcher::isPublicAddress($ip), "{$ip} must not be public");
        }
        foreach (['93.184.216.34', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
            $this->assertTrue(SafeFetcher::isPublicAddress($ip), "{$ip} should be public");
        }
    }

    public function test_attachment_url_to_a_private_address_is_refused_without_a_request(): void
    {
        Http::fake();

        foreach (['http://127.0.0.1/secret', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/admin', 'http://[::1]/x', 'http://localhost/x', 'http://127.0.0.1:5432/'] as $url) {
            $this->app->bind(SafeFetcher::class, fn () => new SafeFetcher(fn (string $host) => $host === 'localhost' ? ['127.0.0.1'] : ['93.184.216.34']));
            $this->send(['path' => $url])->assertStatus(422);
        }

        Http::assertNothingSent();
        $this->assertSame(0, Email::count());
    }

    public function test_attachment_url_with_credentials_or_odd_scheme_or_port_is_refused(): void
    {
        $this->publicDns();
        Http::fake();

        foreach (['http://user:pass@example.com/a.txt', 'ftp://example.com/a.txt', 'http://example.com:8080/a.txt'] as $url) {
            $this->send(['path' => $url])->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_a_redirect_to_a_private_address_is_refused(): void
    {
        $this->publicDns();
        Http::fake(['https://files.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/'])]);

        $this->send(['path' => 'https://files.example.com/report.pdf'])
            ->assertStatus(422)
            ->assertJsonFragment(['name' => 'validation_error']);

        Http::assertSentCount(1);
        $this->assertSame(0, Email::count());
    }

    public function test_a_redirect_to_a_public_address_is_followed(): void
    {
        $this->publicDns();
        Http::fake([
            'https://files.example.com/*' => Http::response('', 302, ['Location' => 'https://cdn.example.com/report.pdf']),
            'https://cdn.example.com/*' => Http::response('PDFDATA', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $this->send(['path' => 'https://files.example.com/report.pdf'])->assertOk();

        $attachment = Email::firstOrFail()->attachments()->firstOrFail();
        $this->assertSame('report.pdf', $attachment->filename);
        $this->assertSame('application/pdf', $attachment->content_type);
    }

    public function test_download_errors_do_not_leak_connection_details(): void
    {
        $this->publicDns();
        Http::fake(['https://files.example.com/*' => fn () => throw new \RuntimeException('cURL error 7: Failed to connect to 10.1.2.3 port 5432')]);

        $response = $this->send(['path' => 'https://files.example.com/x']);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('10.1.2.3', $response->getContent());
        $this->assertStringNotContainsString('cURL', $response->getContent());
    }

    public function test_oversized_download_is_refused(): void
    {
        $this->publicDns();
        Http::fake(['https://files.example.com/*' => Http::response(str_repeat('a', 1024), 200, ['Content-Length' => (string) (41 * 1024 * 1024)])]);

        $this->send(['path' => 'https://files.example.com/big.bin'])->assertStatus(422);
        $this->assertSame(0, Email::count());
    }

    public function test_private_urls_can_be_allowed_explicitly(): void
    {
        config(['mailroute.allow_private_attachment_urls' => true]);
        Http::fake(['http://10.0.0.5:8080/*' => Http::response('INTERNAL', 200)]);

        $this->send(['path' => 'http://10.0.0.5:8080/report.txt'])->assertOk();
        $this->assertSame(1, Email::firstOrFail()->attachments()->count());
    }
}
