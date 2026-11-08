<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Livewire\Providers;
use App\Mail\DriverException;
use App\Mail\Drivers\ResendDriver;
use App\Mail\OutgoingMessage;
use App\Models\Email;
use App\Models\Provider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProviderSenderTest extends TestCase
{
    use RefreshDatabase;

    private function message(string $from = 'Acme <hello@notify.acme.test>', array $replyTo = []): OutgoingMessage
    {
        return new OutgoingMessage(id: 'm1', from: $from, to: ['user@example.com'], subject: 'Hi', text: 'Hi', replyTo: $replyTo);
    }

    private function providerWithFrom(?string $from): Provider
    {
        return $this->provider('P', ['from_address' => $from]);
    }

    public function test_provider_without_default_sender_leaves_the_message_alone(): void
    {
        $m = $this->message();

        $this->assertSame($m, $m->forProvider($this->providerWithFrom(null)));
        $this->assertSame($m, $m->forProvider($this->providerWithFrom('  ')));
    }

    public function test_provider_default_sender_replaces_from_and_keeps_the_original_as_reply_to(): void
    {
        $out = $this->message()->forProvider($this->providerWithFrom('Acme Mail <noreply@mail.acme.test>'));

        $this->assertSame('"Acme Mail" <noreply@mail.acme.test>', $out->from);
        $this->assertSame(['Acme <hello@notify.acme.test>'], $out->replyTo);
        $this->assertSame('m1', $out->id);
        $this->assertSame(['user@example.com'], $out->to);
    }

    public function test_a_plain_default_address_keeps_the_display_name_of_the_email(): void
    {
        $out = $this->message()->forProvider($this->providerWithFrom('noreply@mail.acme.test'));

        $this->assertSame('"Acme" <noreply@mail.acme.test>', $out->from);
    }

    public function test_an_explicit_reply_to_is_not_overwritten(): void
    {
        $out = $this->message(replyTo: ['support@acme.test'])->forProvider($this->providerWithFrom('noreply@mail.acme.test'));

        $this->assertSame(['support@acme.test'], $out->replyTo);
    }

    public function test_same_address_adds_no_reply_to(): void
    {
        $out = $this->message('hello@notify.acme.test')->forProvider($this->providerWithFrom('hello@notify.acme.test'));

        $this->assertSame([], $out->replyTo);
    }

    public function test_each_provider_in_a_failover_sends_with_its_own_sender(): void
    {
        $this->provider('Resend', ['from_address' => 'noreply@verified.acme.test']);
        $this->provider('SMTP', ['from_address' => 'Acme <smtp@notify.acme.test>']);
        $this->drivers->behave('Resend', 'transient');

        $email = Email::create(['from' => 'Acme <hello@notify.acme.test>', 'to' => ['user@example.com'], 'subject' => 'Hi', 'text' => 'Hi', 'status' => 'queued']);
        dispatch_sync(new SendEmailJob($email->id));

        $this->assertSame('sent', $email->fresh()->status);
        $this->assertCount(2, $this->drivers->messages);
        $this->assertSame('"Acme" <noreply@verified.acme.test>', $this->drivers->messages[0]->from);
        $this->assertSame('"Acme" <smtp@notify.acme.test>', $this->drivers->messages[1]->from);
        // the stored email keeps what the API caller sent
        $this->assertSame('Acme <hello@notify.acme.test>', $email->fresh()->from);
    }

    public function test_unverified_domain_error_fails_over_without_tripping_the_breaker(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['statusCode' => 403, 'name' => 'validation_error', 'message' => 'The notify.zenhosta.com domain is not verified. Please, add and verify your domain on https://resend.com/domains'], 403)]);

        try {
            (new ResendDriver(['api_key' => 're_x']))->send($this->message());
            $this->fail('expected a DriverException');
        } catch (DriverException $e) {
            $this->assertTrue($e->retryable, 'another provider should be tried');
            $this->assertFalse($e->providerFault, 'a domain that is not verified is not a broken provider');
            $this->assertNull($e->pauseSeconds);
            $this->assertStringContainsString('not verified', $e->getMessage());
        }
    }

    public function test_bad_credentials_still_count_as_a_provider_fault(): void
    {
        Http::fake(['api.resend.com/*' => Http::response(['message' => 'API key is invalid'], 403)]);

        try {
            (new ResendDriver(['api_key' => 're_x']))->send($this->message());
            $this->fail('expected a DriverException');
        } catch (DriverException $e) {
            $this->assertTrue($e->providerFault);
        }
    }

    public function test_the_provider_form_saves_and_loads_the_default_sender(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(Providers::class)
            ->call('create')
            ->set('form.type', 'resend')
            ->set('form.name', 'Resend main')
            ->set('form.from_address', '  Acme <noreply@verified.acme.test> ')
            ->set('config.api_key', 're_secret')
            ->call('save')
            ->assertHasNoErrors();

        $p = Provider::where('name', 'Resend main')->firstOrFail();
        $this->assertSame('Acme <noreply@verified.acme.test>', $p->from_address);

        Livewire::test(Providers::class)->call('edit', $p->id)->assertSet('form.from_address', 'Acme <noreply@verified.acme.test>');

        Livewire::test(Providers::class)
            ->call('edit', $p->id)
            ->set('form.from_address', '')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertNull($p->fresh()->from_address);
    }

    public function test_an_invalid_default_sender_is_rejected(): void
    {
        $this->actingAs($this->owner());

        Livewire::test(Providers::class)
            ->call('create')
            ->set('form.type', 'resend')
            ->set('form.name', 'Resend main')
            ->set('form.from_address', 'not an address')
            ->set('config.api_key', 're_secret')
            ->call('save')
            ->assertHasErrors(['form.from_address']);

        $this->assertSame(0, Provider::count());
    }

    public function test_the_test_email_form_starts_from_the_provider_sender(): void
    {
        $this->actingAs($this->owner());
        $a = $this->provider('A', ['from_address' => 'a@verified.acme.test']);
        $b = $this->provider('B', ['from_address' => 'b@verified.acme.test']);

        Livewire::test(Providers::class)
            ->call('openTest', $a->id)->assertSet('testFrom', 'a@verified.acme.test')
            ->call('openTest', $b->id)->assertSet('testFrom', 'b@verified.acme.test');
    }
}
