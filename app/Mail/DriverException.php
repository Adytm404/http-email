<?php

namespace App\Mail;

use RuntimeException;
use Throwable;

/**
 * A failed send, classified so the router knows what to do next.
 *
 * - retryable: another provider (or a later retry) might succeed.
 * - providerFault: the provider itself is broken (down, bad credentials) and
 *   should count towards its circuit breaker.
 * Not retryable means the message itself was rejected (bad recipient, invalid
 * payload) and no provider will accept it.
 */
class DriverException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = true,
        public readonly bool $providerFault = false,
        public readonly ?string $errorCode = null,
        public readonly array $response = [],
        ?Throwable $previous = null,
        /** Set when the provider ran out of sending quota: skip it for this long. */
        public readonly ?int $pauseSeconds = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The provider's own sending limit was hit (e.g. Gmail's daily limit): try the next one and rest this one. */
    public static function quota(string $message, ?string $code = null, int $pauseSeconds = 3600, array $response = []): self
    {
        return new self($message, retryable: true, providerFault: false, errorCode: $code, response: $response, pauseSeconds: $pauseSeconds);
    }

    public static function permanent(string $message, ?string $code = null, array $response = []): self
    {
        return new self($message, retryable: false, providerFault: false, errorCode: $code, response: $response);
    }

    public static function providerDown(string $message, ?string $code = null, array $response = [], ?Throwable $previous = null): self
    {
        return new self($message, retryable: true, providerFault: true, errorCode: $code, response: $response, previous: $previous);
    }

    public static function transient(string $message, ?string $code = null, array $response = []): self
    {
        return new self($message, retryable: true, providerFault: false, errorCode: $code, response: $response);
    }
}
