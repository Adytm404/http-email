<?php

namespace App\Jobs;

use App\Mail\DriverException;
use App\Mail\DriverFactory;
use App\Mail\OutgoingMessage;
use App\Models\Email;
use App\Models\EmailAttempt;
use App\Models\Provider;
use App\Routing\CircuitBreaker;
use App\Routing\EmailRouter;
use App\Routing\QuotaManager;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

class SendEmailJob implements ShouldQueue
{
    use Queueable;

    /** Total rounds through the provider list before giving up. */
    public int $tries = 4;

    public int $timeout = 120;

    /** Delay (seconds) before round 2, 3 and 4. */
    public const BACKOFF = [60, 300, 1800];

    /** An email waits at most this long for sending capacity (provider limits or rest periods) before it fails. */
    public const MAX_CAPACITY_WAIT_HOURS = 24;

    public function __construct(public string $emailId)
    {
        $this->onQueue('emails');
    }

    public function handle(EmailRouter $router, QuotaManager $quota, CircuitBreaker $breaker, DriverFactory $drivers): void
    {
        $email = Email::with('attachments')->find($this->emailId);
        if (! $email || in_array($email->status, ['sent', 'failed', 'canceled'], true)) {
            return;
        }

        // Rescheduled to a later time: the job queued for the new time will send it.
        if ($email->status === 'scheduled' && $email->scheduled_at?->isAfter(now()->addSeconds(5))) {
            return;
        }

        $rule = $router->ruleFor($email);
        // The providers of the route come first. Every other usable provider follows as a last resort, so a
        // route that is too narrow (or whose providers are all out of quota) never fails an email while
        // another provider could still send it.
        $routed = $router->candidates($email, $rule);
        $candidates = $routed->concat($router->fallbackCandidates($routed))->values();

        $email->forceFill([
            'status' => 'sending',
            'attempts' => $email->attempts + 1,
            'routing_rule_id' => $rule?->id,
        ])->save();

        if ($candidates->isEmpty()) {
            // Everything is resting (cooldown): wait for the first provider instead of burning retry rounds.
            if ($this->waitForCapacity($email, $router->nextAvailability(), 'All providers are resting')) {
                return;
            }

            $this->retryOrFail($email, 'No provider available'.($rule ? " for route \"{$rule->name}\"" : ''));

            return;
        }

        $message = OutgoingMessage::fromEmail($email);
        $lastError = 'All providers failed';
        $resetsAt = [];
        $rejections = 0;

        foreach ($candidates as $provider) {
            if (! $quota->reserve($provider)) {
                $this->log($email, $provider, 'skipped', 'quota', 'Rate limit reached');
                $lastError = "{$provider->name}: rate limit reached";
                if ($at = $quota->nextAvailableAt($provider)) {
                    $resetsAt[] = $at;
                }

                continue;
            }

            $started = hrtime(true);

            try {
                $result = $drivers->make($provider)->send($message->forProvider($provider));
            } catch (DriverException $e) {
                $quota->release($provider);
                $this->log($email, $provider, 'failed', $e->errorCode, $e->getMessage(), $e->response, $started);
                $lastError = "{$provider->name}: {$e->getMessage()}";

                if ($e->pauseSeconds) {
                    // Out of sending quota: rest this provider so the next emails go straight elsewhere.
                    $breaker->pause($provider, $e->pauseSeconds, $e->getMessage());
                } elseif ($e->providerFault) {
                    $breaker->recordFailure($provider, $e->getMessage());
                }

                if (! $e->retryable) {
                    // The message was rejected. Another provider may still accept it (a sender or domain this
                    // one does not know), so the email only fails once a second provider rejects it too.
                    if (++$rejections >= 2 || $candidates->count() === 1) {
                        $this->markFailed($email, $lastError);

                        return;
                    }
                }

                continue;
            } catch (RfcComplianceException $e) {
                $quota->release($provider);
                $this->log($email, $provider, 'failed', 'invalid_address', $e->getMessage(), [], $started);
                $this->markFailed($email, $e->getMessage());

                return;
            } catch (Throwable $e) {
                $quota->release($provider);
                $this->log($email, $provider, 'failed', 'exception', $e->getMessage(), [], $started);
                $breaker->recordFailure($provider, $e->getMessage());
                $lastError = "{$provider->name}: {$e->getMessage()}";

                continue;
            }

            $this->log($email, $provider, 'sent', null, null, $result->response, $started);
            $breaker->recordSuccess($provider);

            $email->forceFill([
                'status' => 'sent',
                'provider_id' => $provider->id,
                'provider_message_id' => $result->messageId,
                'sent_at' => now(),
                'failed_reason' => null,
            ])->save();

            return;
        }

        // Some providers are only out of quota: that is not an error. Wait for the first one to reset.
        if ($resetsAt !== [] && $this->waitForCapacity($email, collect($resetsAt)->sortBy(fn ($t) => $t->getTimestamp())->first(), $lastError)) {
            return;
        }

        $this->retryOrFail($email, $lastError);
    }

    public function failed(?Throwable $e): void
    {
        $email = Email::find($this->emailId);
        if ($email && ! in_array($email->status, ['sent', 'canceled'], true)) {
            $email->forceFill(['status' => 'failed', 'failed_reason' => $e?->getMessage() ?? 'Job failed'])->save();
        }
    }

    /**
     * Put the email back in the queue until a provider can send again, without using up a retry round.
     * Returns true when the email was handled (queued again, or failed because it waited too long) and
     * false when there is nothing to wait for or the job cannot be delayed (sync queue).
     */
    private function waitForCapacity(Email $email, ?CarbonInterface $at, string $reason): bool
    {
        if ($at === null || ! $this->job || $this->job->getConnectionName() === 'sync') {
            return false;
        }

        if ($email->created_at->diffInHours(now()) >= self::MAX_CAPACITY_WAIT_HOURS) {
            $this->markFailed($email, 'No sending capacity within '.self::MAX_CAPACITY_WAIT_HOURS.' hours. Last error: '.$reason);

            return true;
        }

        // A little jitter so a whole backlog does not wake up in the same second.
        $delay = max(30, (int) now()->diffInSeconds($at, false)) + random_int(0, 90);

        $email->forceFill([
            'status' => 'queued',
            'failed_reason' => mb_substr('Waiting for sending capacity until '.$at->copy()->utc()->format('H:i').' UTC. '.$reason, 0, 2000),
        ])->save();

        self::dispatch($email->id)->delay(now()->addSeconds($delay));

        return true;
    }

    private function retryOrFail(Email $email, string $reason): void
    {
        $round = $this->attempts();

        if ($round >= $this->tries || ! $this->job || $this->job->getConnectionName() === 'sync') {
            $this->markFailed($email, $reason);

            return;
        }

        $email->forceFill(['status' => 'queued', 'failed_reason' => $reason])->save();
        $this->release(self::BACKOFF[$round - 1] ?? self::BACKOFF[array_key_last(self::BACKOFF)]);
    }

    private function markFailed(Email $email, string $reason): void
    {
        $email->forceFill(['status' => 'failed', 'failed_reason' => mb_substr($reason, 0, 2000)])->save();
    }

    private function log(Email $email, Provider $provider, string $status, ?string $code, ?string $message, array $response = [], ?int $started = null): void
    {
        EmailAttempt::create([
            'email_id' => $email->id,
            'provider_id' => $provider->id,
            'status' => $status,
            'error_code' => $code,
            'error_message' => $message !== null ? mb_substr($message, 0, 2000) : null,
            'response' => $response ?: null,
            'duration_ms' => $started ? (int) ((hrtime(true) - $started) / 1_000_000) : 0,
        ]);
    }
}
