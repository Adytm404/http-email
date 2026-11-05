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
        $candidates = $router->candidates($email, $rule);

        $email->forceFill([
            'status' => 'sending',
            'attempts' => $email->attempts + 1,
            'routing_rule_id' => $rule?->id,
        ])->save();

        if ($candidates->isEmpty()) {
            $this->retryOrFail($email, 'No provider available'.($rule ? " for route \"{$rule->name}\"" : ''));

            return;
        }

        $message = OutgoingMessage::fromEmail($email);
        $lastError = 'All providers failed';

        foreach ($candidates as $provider) {
            if (! $quota->reserve($provider)) {
                $this->log($email, $provider, 'skipped', 'quota', 'Rate limit reached');
                $lastError = "{$provider->name}: rate limit reached";

                continue;
            }

            $started = hrtime(true);

            try {
                $result = $drivers->make($provider)->send($message);
            } catch (DriverException $e) {
                $quota->release($provider);
                $this->log($email, $provider, 'failed', $e->errorCode, $e->getMessage(), $e->response, $started);

                if ($e->providerFault) {
                    $breaker->recordFailure($provider, $e->getMessage());
                }

                if (! $e->retryable) {
                    $this->markFailed($email, "{$provider->name}: {$e->getMessage()}");

                    return;
                }

                $lastError = "{$provider->name}: {$e->getMessage()}";

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

        $this->retryOrFail($email, $lastError);
    }

    public function failed(?Throwable $e): void
    {
        $email = Email::find($this->emailId);
        if ($email && ! in_array($email->status, ['sent', 'canceled'], true)) {
            $email->forceFill(['status' => 'failed', 'failed_reason' => $e?->getMessage() ?? 'Job failed'])->save();
        }
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
