<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\Email;
use App\Models\Provider;
use App\Models\Setting;
use App\Routing\EmailRouter;
use App\Routing\QuotaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutingTest extends TestCase
{
    use RefreshDatabase;

    private function email(array $attrs = []): Email
    {
        return Email::create(array_merge([
            'from' => 'App <noreply@acme.test>',
            'to' => ['user@gmail.com'],
            'subject' => 'Hi',
            'text' => 'Hi',
            'status' => 'queued',
        ], $attrs));
    }

    private function send(Email $email): Email
    {
        dispatch_sync(new SendEmailJob($email->id));

        return $email->fresh();
    }

    public function test_failover_uses_next_provider_when_first_is_down(): void
    {
        $this->rule('default', ['A' => [1, 100], 'B' => [2, 100]], ['is_default' => true]);
        $this->drivers->behave('A', 'down');

        $email = $this->send($this->email());

        $this->assertSame('sent', $email->status);
        $this->assertSame('B', $email->provider->name);
        $this->assertSame(['failed', 'sent'], $email->attemptLogs->pluck('status')->all());
    }

    public function test_sending_limit_falls_back_and_rests_the_provider(): void
    {
        $this->rule('default', ['Gmail A' => [1, 100], 'Gmail B' => [2, 100]], ['is_default' => true]);
        $this->drivers->behave('Gmail A', 'quota');

        $email = $this->send($this->email());

        $this->assertSame('sent', $email->status);
        $this->assertSame('Gmail B', $email->provider->name);

        $a = Provider::where('name', 'Gmail A')->first();
        $this->assertSame('limited', $a->health);
        $this->assertTrue($a->isCoolingDown());
        $this->assertEqualsWithDelta(3600, now()->diffInSeconds($a->cooldown_until), 5);

        // The next email skips the exhausted account entirely.
        $this->drivers->sent = [];
        $this->send($this->email());
        $this->assertSame(['Gmail B'], array_column($this->drivers->sent, 0));
    }

    public function test_a_rejection_is_confirmed_by_a_second_provider_before_the_email_fails(): void
    {
        $this->rule('default', ['A' => [1, 100], 'B' => [2, 100], 'C' => [3, 100]], ['is_default' => true]);
        $this->drivers->behave('A', 'reject')->behave('B', 'reject');

        $email = $this->send($this->email());

        $this->assertSame('failed', $email->status);
        $this->assertSame(['A', 'B'], array_column($this->drivers->sent, 0), 'C is not tried once two providers agreed');
    }

    public function test_a_provider_specific_rejection_falls_over_to_the_next_provider(): void
    {
        $this->rule('default', ['A' => [1, 100], 'B' => [2, 100]], ['is_default' => true]);
        $this->drivers->behave('A', 'reject');

        $email = $this->send($this->email());

        $this->assertSame('sent', $email->status);
        $this->assertSame('B', $email->provider->name);
    }

    public function test_a_single_provider_rejection_fails_immediately(): void
    {
        $this->rule('default', ['A' => [1, 100]], ['is_default' => true]);
        $this->drivers->behave('A', 'reject');

        $email = $this->send($this->email());

        $this->assertSame('failed', $email->status);
        $this->assertCount(1, $this->drivers->sent);
    }

    public function test_condition_rules_match_before_default(): void
    {
        $this->rule('default', ['A' => [1, 100]], ['is_default' => true]);
        $this->rule('gmail', ['B' => [1, 100]], ['priority' => 1, 'conditions' => ['to_domain' => 'gmail.com']]);
        $this->rule('promo', ['C' => [1, 100]], ['priority' => 2, 'conditions' => ['tag' => 'category:promo']]);
        $this->rule('wild', ['D' => [1, 100]], ['priority' => 3, 'conditions' => ['from_domain' => '*.example.org']]);

        $router = app(EmailRouter::class);

        $this->assertSame('gmail', $router->ruleFor($this->email())->name);
        $this->assertSame('promo', $router->ruleFor($this->email(['to' => ['x@yahoo.com'], 'tags' => [['name' => 'category', 'value' => 'promo']]]))->name);
        $this->assertSame('wild', $router->ruleFor($this->email(['to' => ['x@yahoo.com'], 'from' => 'a@mail.example.org']))->name);
        $this->assertSame('default', $router->ruleFor($this->email(['to' => ['x@yahoo.com']]))->name);
        $this->assertSame('promo', $router->ruleFor($this->email(['forced_route' => 'promo']))->name);
    }

    public function test_weighted_distribution_follows_weights(): void
    {
        $rule = $this->rule('w', ['A' => [1, 70], 'B' => [2, 30]], ['is_default' => true, 'strategy' => 'weighted']);
        $router = app(EmailRouter::class);
        $email = $this->email();

        $counts = ['A' => 0, 'B' => 0];
        for ($i = 0; $i < 5000; $i++) {
            $counts[$router->candidates($email, $rule)->first()->name]++;
        }

        $this->assertEqualsWithDelta(0.70, $counts['A'] / 5000, 0.05);
        // The other provider is still listed as a fallback.
        $this->assertCount(2, $router->candidates($email, $rule));
    }

    public function test_quota_moves_traffic_to_next_provider(): void
    {
        $this->provider('A', ['limit_per_minute' => 2]);
        $this->rule('default', ['A' => [1, 100], 'B' => [2, 100]], ['is_default' => true]);

        foreach (range(1, 3) as $_) {
            $this->send($this->email());
        }

        $this->assertSame(['A', 'A', 'B'], $this->drivers->sentVia());
        $this->assertSame(['minute' => ['used' => 2, 'limit' => 2]], app(QuotaManager::class)->usage(Provider::where('name', 'A')->first()));
    }

    public function test_quota_with_multiple_windows_rolls_back_on_exhaustion(): void
    {
        $p = $this->provider('A', ['limit_per_minute' => 5, 'limit_per_hour' => 1]);
        $quota = app(QuotaManager::class);

        $this->assertTrue($quota->reserve($p));
        $this->assertFalse($quota->reserve($p));
        $this->assertSame(1, $quota->usage($p)['minute']['used']);
    }

    public function test_circuit_breaker_trips_and_skips_provider(): void
    {
        Setting::put('breaker_threshold', 2);
        $this->rule('default', ['A' => [1, 100], 'B' => [2, 100]], ['is_default' => true]);
        $this->drivers->behave('A', 'down');

        $this->send($this->email());
        $this->send($this->email());
        $a = Provider::where('name', 'A')->first();
        $this->assertSame('down', $a->health);
        $this->assertTrue($a->isCoolingDown());

        $this->drivers->sent = [];
        $this->send($this->email());
        $this->assertSame([['B']], array_map(fn ($s) => [$s[0]], $this->drivers->sent));
    }

    public function test_all_providers_down_marks_failed_on_sync_queue(): void
    {
        $this->rule('default', ['A' => [1, 100]], ['is_default' => true]);
        $this->drivers->behave('A', 'transient');

        $email = $this->send($this->email());

        $this->assertSame('failed', $email->status);
        $this->assertStringContainsString('451', $email->failed_reason);
    }

    public function test_without_rules_all_active_providers_are_used(): void
    {
        $this->provider('A', ['priority' => 2]);
        $this->provider('B', ['priority' => 1]);
        $this->provider('C', ['priority' => 0, 'is_active' => false]);

        $this->assertSame(['B', 'A'], app(EmailRouter::class)->candidates($this->email())->pluck('name')->all());
    }
}
