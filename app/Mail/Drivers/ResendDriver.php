<?php

namespace App\Mail\Drivers;

use App\Mail\DriverException;
use App\Mail\DriverResult;
use App\Mail\OutgoingMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ResendDriver extends HttpDriver
{
    public function send(OutgoingMessage $m): DriverResult
    {
        $payload = array_filter([
            'from' => $m->from,
            'to' => $m->to,
            'cc' => $m->cc ?: null,
            'bcc' => $m->bcc ?: null,
            'reply_to' => $m->replyTo ?: null,
            'subject' => $m->subject,
            'html' => $m->html,
            'text' => $m->text,
            'headers' => $m->headers ?: null,
            'tags' => $m->tags ? array_map(fn ($t) => [
                // Resend only allows ASCII letters, numbers, _ and - in tags.
                'name' => preg_replace('/[^A-Za-z0-9_-]/', '_', $t['name']),
                'value' => preg_replace('/[^A-Za-z0-9_-]/', '_', (string) ($t['value'] ?? '')),
            ], $m->tags) : null,
            'attachments' => $m->attachments ? array_map(fn ($a) => array_filter([
                'filename' => $a['filename'],
                'content' => base64_encode($a['content']),
                'content_type' => $a['content_type'] ?? null,
                'content_id' => $a['content_id'] ?? null,
            ]), $m->attachments) : null,
        ], fn ($v) => $v !== null);

        $response = $this->call(fn () => $this->client()
            ->withHeaders(['Idempotency-Key' => $m->id])
            ->post('/emails', $payload));

        return new DriverResult($response->json('id'), $response->json() ?? []);
    }

    public function test(): void
    {
        try {
            $this->call(fn () => $this->client()->get('/domains'));
        } catch (DriverException $e) {
            // A sending-only key cannot list domains but is still valid.
            if (($e->response['name'] ?? null) === 'restricted_api_key') {
                return;
            }
            throw $e;
        }
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->config['base_url'] ?? 'https://api.resend.com', '/'))
            ->withToken($this->config['api_key'] ?? '')
            ->acceptJson()
            ->timeout($this->timeout());
    }
}
