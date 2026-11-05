<?php

namespace App\Mail;

final class DriverResult
{
    public function __construct(
        public readonly ?string $messageId,
        public readonly array $response = [],
    ) {}
}
