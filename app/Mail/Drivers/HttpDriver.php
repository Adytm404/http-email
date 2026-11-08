<?php

namespace App\Mail\Drivers;

use App\Mail\DriverException;
use App\Mail\Drivers\Contracts\EmailDriver;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

abstract class HttpDriver implements EmailDriver
{
    public function __construct(protected readonly array $config) {}

    /**
     * Run an HTTP call and turn transport errors / bad statuses into DriverExceptions.
     *
     * @param  Closure(): Response  $call
     */
    protected function call(Closure $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw DriverException::providerDown($e->getMessage(), 'connection', previous: $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw $this->classify($response);
    }

    protected function classify(Response $response): DriverException
    {
        $status = $response->status();
        $body = $response->json() ?? ['body' => mb_substr($response->body(), 0, 1000)];
        $message = $this->errorMessage($body) ?? "HTTP {$status}";
        $code = (string) $status;

        // "Domain not verified" / unverified sender identity: this provider cannot send from this address,
        // but it is not broken. Try the next provider without counting a failure against this one.
        if (in_array($status, [400, 403, 422], true)
            && preg_match('/not verified|unverified|verify your domain|verified sender|sender identity|from address does not match/i', $message)) {
            return DriverException::transient($message, $code, $body);
        }

        return match (true) {
            // Bad or revoked credentials, wrong domain/region: the provider is unusable.
            in_array($status, [401, 403, 404], true) => DriverException::providerDown($message, $code, $body),
            $status === 429 && preg_match('/daily|quota|per day|monthly/i', $message) => DriverException::quota($message, $code, 3600, $body),
            $status === 429 => DriverException::transient($message, $code, $body),
            $status >= 500 => DriverException::providerDown($message, $code, $body),
            // 400 / 422: the provider rejected this particular message.
            default => DriverException::permanent($message, $code, $body),
        };
    }

    protected function errorMessage(array $body): ?string
    {
        return $body['message']
            ?? $body['error']
            ?? (isset($body['errors'][0]['message']) ? $body['errors'][0]['message'] : null);
    }

    protected function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? 20);
    }
}
