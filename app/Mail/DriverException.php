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
    ) {
        parent::__construct($message, 0, $previous);
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
