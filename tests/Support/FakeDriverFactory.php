<?php

namespace Tests\Support;

use App\Mail\DriverException;
use App\Mail\DriverFactory;
use App\Mail\DriverResult;
use App\Mail\Drivers\Contracts\EmailDriver;
use App\Mail\OutgoingMessage;
use App\Models\Provider;

/**
 * Drivers whose behaviour is set per provider name:
 *   $factory->behave('A', 'ok' | 'down' | 'reject' | 'transient')
 * Every send is recorded in $sent as [provider name, message id].
 */
class FakeDriverFactory extends DriverFactory
{
    public array $behaviour = [];

    public array $sent = [];

    /** @var list<OutgoingMessage> the messages exactly as each driver received them */
    public array $messages = [];

    public function behave(string $provider, string $how): static
    {
        $this->behaviour[$provider] = $how;

        return $this;
    }

    public function make(Provider $provider): EmailDriver
    {
        $factory = $this;

        return new class($provider, $factory) implements EmailDriver
        {
            public function __construct(private Provider $provider, private FakeDriverFactory $factory) {}

            public function send(OutgoingMessage $message): DriverResult
            {
                $how = $this->factory->behaviour[$this->provider->name] ?? 'ok';
                $this->factory->sent[] = [$this->provider->name, $message->id, $how];
                $this->factory->messages[] = $message;

                return match ($how) {
                    'down' => throw DriverException::providerDown('connection refused', 'connection'),
                    'reject' => throw DriverException::permanent('550 mailbox unavailable', '550'),
                    'transient' => throw DriverException::transient('451 try later', '451'),
                    'quota' => throw \App\Mail\Drivers\SmtpDriver::classifyReply(550, 'Expected response code "354" but got code "550", with message "550-5.4.5 Daily user sending limit exceeded."'),
                    default => new DriverResult('msg-'.$this->provider->name),
                };
            }

            public function test(): void {}
        };
    }

    public function sentVia(): array
    {
        return array_map(fn ($s) => $s[0], array_filter($this->sent, fn ($s) => $s[2] === 'ok'));
    }
}
