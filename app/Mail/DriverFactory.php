<?php

namespace App\Mail;

use App\Mail\Drivers\Contracts\EmailDriver;
use App\Mail\Drivers\MailgunDriver;
use App\Mail\Drivers\ResendDriver;
use App\Mail\Drivers\SendGridDriver;
use App\Mail\Drivers\SmtpDriver;
use App\Models\Provider;
use InvalidArgumentException;

class DriverFactory
{
    public function make(Provider $provider): EmailDriver
    {
        $config = $provider->config ?? [];

        return match ($provider->type) {
            'smtp' => new SmtpDriver($config),
            'resend' => new ResendDriver($config),
            'sendgrid' => new SendGridDriver($config),
            'mailgun' => new MailgunDriver($config),
            default => throw new InvalidArgumentException("Unknown provider type [{$provider->type}]"),
        };
    }

    /**
     * Form fields per provider type, used by the dashboard.
     *
     * @return array<string, list<array{key: string, label: string, type: string, required?: bool, secret?: bool, options?: array, default?: mixed, hint?: string}>>
     */
    public static function fields(): array
    {
        return [
            'smtp' => [
                ['key' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true, 'hint' => 'smtp.example.com'],
                ['key' => 'port', 'label' => 'Port', 'type' => 'number', 'required' => true, 'default' => 587],
                ['key' => 'encryption', 'label' => 'Encryption', 'type' => 'select', 'default' => 'starttls', 'options' => [
                    'starttls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None (25)',
                ]],
                ['key' => 'username', 'label' => 'Username', 'type' => 'text'],
                ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'secret' => true],
                ['key' => 'timeout', 'label' => 'Timeout (seconds)', 'type' => 'number', 'default' => 20],
            ],
            'resend' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true, 'secret' => true, 'hint' => 're_...'],
            ],
            'sendgrid' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true, 'secret' => true, 'hint' => 'SG....'],
            ],
            'mailgun' => [
                ['key' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true, 'secret' => true],
                ['key' => 'domain', 'label' => 'Sending domain', 'type' => 'text', 'required' => true, 'hint' => 'mg.example.com'],
                ['key' => 'region', 'label' => 'Region', 'type' => 'select', 'default' => 'us', 'options' => ['us' => 'US', 'eu' => 'EU']],
            ],
        ];
    }
}
