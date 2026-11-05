<?php

namespace App\Mail\Drivers;

use App\Mail\DriverResult;
use App\Mail\OutgoingMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SendGridDriver extends HttpDriver
{
    public function send(OutgoingMessage $m): DriverResult
    {
        $addr = fn (array $list) => array_map(OutgoingMessage::parseAddress(...), $list);

        $content = [];
        if ($m->text !== null) {
            $content[] = ['type' => 'text/plain', 'value' => $m->text];
        }
        if ($m->html !== null) {
            $content[] = ['type' => 'text/html', 'value' => $m->html];
        }

        $personalization = array_filter([
            'to' => $addr($m->to),
            'cc' => $m->cc ? $addr($m->cc) : null,
            'bcc' => $m->bcc ? $addr($m->bcc) : null,
        ]);

        $payload = array_filter([
            'personalizations' => [$personalization],
            'from' => OutgoingMessage::parseAddress($m->from),
            'reply_to_list' => $m->replyTo ? $addr($m->replyTo) : null,
            'subject' => $m->subject,
            'content' => $content,
            'headers' => $m->headers ? array_map('strval', $m->headers) : null,
            'categories' => $m->tags ? array_slice(array_map(fn ($t) => mb_substr($t['name'].':'.($t['value'] ?? ''), 0, 255), $m->tags), 0, 10) : null,
            'custom_args' => ['mailroute_id' => $m->id],
            'attachments' => $m->attachments ? array_map(fn ($a) => array_filter([
                'content' => base64_encode($a['content']),
                'filename' => $a['filename'],
                'type' => $a['content_type'] ?? null,
                'disposition' => ! empty($a['content_id']) ? 'inline' : 'attachment',
                'content_id' => $a['content_id'] ?? null,
            ]), $m->attachments) : null,
        ], fn ($v) => $v !== null && $v !== []);

        $response = $this->call(fn () => $this->client()->post('/v3/mail/send', $payload));

        return new DriverResult($response->header('X-Message-Id') ?: null, ['status' => $response->status()]);
    }

    public function test(): void
    {
        $this->call(fn () => $this->client()->get('/v3/scopes'));
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.sendgrid.com')
            ->withToken($this->config['api_key'] ?? '')
            ->acceptJson()
            ->timeout($this->timeout());
    }
}
