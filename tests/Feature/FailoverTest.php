<?php

namespace Tests\Feature;

use App\Jobs\SendEmailJob;
use App\Models\Email;
use App\Models\Provider;
use App\Routing\QuotaManager;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class FailoverTest extends TestCase
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

    /** Run the job as a worker on a real (non-sync) queue would, so it can be delayed or released. */
    private function work(Email $email, int $attempt = 1): Email
    {
        $job = new SendEmailJob($email->id);
        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('getConnectionName')->andReturn('database');
        $queued->shouldReceive('attempts')->andReturn($attempt);
        $queued->shouldReceive('release')->andReturnNull();
        $job->setJob($queued);

        app()->call([$job, 'handle']);

        return $email->fresh();
    }

    public function test_providers_outside_the_route_are_used_when_the_routed_ones_fail(): void
    {
        $this->rule('default', ['A' => [1, 100], 'B' => [2, 100]], ['is_default' => true]);
        $this->provider('Outside', ['priority' => 5]);
        $this->drivers->behave('A', 'down')->behave('B', 'down');

        $email = $this->work($this->email());

        $this->assertSame('sent', $email->status);
        $this->assertSame('Outside', $email->provider->name);
        $this->assertSame(['A', 'B', 'Outside'], array_column($this->drivers->sent, 0));
    }

    public function test_outside_providers_are_tried_in_priority_order_and_only_when_usable(): void
    {
        $this->rule('default', ['A' => [1, 100]], ['is_default' => true]);
        $this->provider('Late', ['priority' => 20]);
        $this->provider('Early', ['priority' => 5]);
        $this->provider('Off', ['priority' => 1, 'is_active' => false]);
        $this->provider('Resting', ['priority' => 2, 'cooldown_until' => now()->addHour()]);
        $this->drivers->behave('A', 'down')->behave('Early', 'down');

        $email = $this->work($this->email());

        $this->assertSame('Late', $email->provider->name);
        $this->assertSame(['A', 'Early', 'Late'], array_column($this->drivers->sent, 0));
    }

    public function test_a_route_out_of_quota_overflows_to_other_providers(): void
    {
        $a = $this->provider('A', ['limit_per_hour' => 1]);
        $this->rule('default', ['A' => [1, 100]], ['is_default' => true]);
        $this->provider('Spare', ['priority' => 9]);

        $first = $this->work($this->email());
        $second = $this->work($this->email());

        $this->assertSame('A', $first->provider->name);
        $this->assertSame('sent', $second->status);
        $this->assertSame('Spare', $second->provider->name);
    }

    public function test_when_every_provider_is_out_of_quota_the_email_waits_instead_of_failing(): void
    {
        Queue::fake();
        $this->provider('A', ['limit_per_hour' => 1]);
        $this->provider('B', ['limit_per_hour' => 1]);
        $this->work($this->email());
        $this->work($this->email());

        $email = $this->work($this->email());

        $this->assertSame('queued', $email->status);
        $this->assertStringContainsString('Waiting for sending capacity', $email->failed_reason);
        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use ($email) {
            $seconds = now()->diffInSeconds($job->delay, false);

            // woken up right after the hour rolls over, never in the past
            return $job->emailId === $email->id && $seconds >= 25 && $seconds <= 3600 + 95;
        });
    }

    public function test_a_waiting_email_does_not_use_up_its_retry_rounds(): void
    {
        Queue::fake();
        $this->provider('A', ['limit_per_hour' => 1]);
        $this->work($this->email());

        // already on its last round: still waits rather than failing
        $email = $this->work($this->email(), attempt: 4);

        $this->assertSame('queued', $email->status);
    }

    public function test_an_email_that_waited_a_day_for_capacity_finally_fails(): void
    {
        Queue::fake();
        $this->provider('A', ['limit_per_hour' => 1]);
        $this->work($this->email());
        $old = $this->email();
        $old->forceFill(['created_at' => now()->subHours(SendEmailJob::MAX_CAPACITY_WAIT_HOURS + 1)])->saveQuietly();

        $email = $this->work($old);

        $this->assertSame('failed', $email->status);
        $this->assertStringContainsString('No sending capacity within 24 hours', $email->failed_reason);
        Queue::assertNothingPushed();
    }

    public function test_when_all_providers_are_resting_the_email_waits_for_the_first_to_return(): void
    {
        Queue::fake();
        $this->provider('A', ['cooldown_until' => now()->addMinutes(50)]);
        $this->provider('B', ['cooldown_until' => now()->addMinutes(20)]);

        $email = $this->work($this->email());

        $this->assertSame('queued', $email->status);
        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) {
            $seconds = now()->diffInSeconds($job->delay, false);

            return $seconds >= 19 * 60 && $seconds <= 21 * 60 + 95;
        });
    }

    public function test_without_any_active_provider_the_email_still_follows_the_retry_rounds(): void
    {
        $this->provider('Off', ['is_active' => false]);

        $email = $this->work($this->email(), attempt: 4);

        $this->assertSame('failed', $email->status);
        $this->assertStringContainsString('No provider available', $email->failed_reason);
    }

    public function test_next_available_time_is_the_end_of_the_longest_exhausted_window(): void
    {
        $p = $this->provider('A', ['limit_per_hour' => 1, 'limit_per_day' => 2]);
        $quota = app(QuotaManager::class);
        $this->assertNull($quota->nextAvailableAt($p));

        $quota->reserve($p);
        $hourEnd = now('UTC')->startOfHour()->addHour();
        $this->assertTrue($quota->nextAvailableAt($p)->equalTo($hourEnd));

        // exhaust the day as well: now the day is what blocks it
        \Illuminate\Support\Facades\DB::table('provider_usage')->where('window', 'day')->update(['count' => 2]);
        $this->assertTrue($quota->nextAvailableAt($p)->equalTo(now('UTC')->startOfDay()->addDay()));
    }

    public function test_sync_queue_never_loops_on_a_full_quota(): void
    {
        $this->provider('A', ['limit_per_hour' => 1]);
        dispatch_sync(new SendEmailJob($this->email()->id));
        $second = $this->email();

        dispatch_sync(new SendEmailJob($second->id));

        $this->assertSame('failed', $second->fresh()->status);
    }
}
