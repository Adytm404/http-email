<?php

namespace App\Mail\Drivers;

use App\Mail\DriverResult;
use App\Mail\OutgoingMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class MailgunDriver extends HttpDriver
{
    public function send(OutgoingMessage $m): DriverResult
    {
        // Mailgun takes repeated multipart fields for lists (to, cc, o:tag ...).
        $fields = [
            ['from', $m->from],
            ['subject', $m->subject],
            ['v:mailroute_id', $m->id],
        ];
        foreach ($m->to as $v) {
            $fields[] = ['to', $v];
        }
        foreach ($m->cc as $v) {
            $fields[] = ['cc', $v];
        }
        foreach ($m->bcc as $v) {
            $fields[] = ['bcc', $v];
        }
        if ($m->replyTo) {
            $fields[] = ['h:Reply-To', implode(', ', $m->replyTo)];
        }
        if ($m->text !== null) {
            $fields[] = ['text', $m->text];
        }
        if ($m->html !== null) {
            $fields[] = ['html', $m->html];
        }
        foreach ($m->headers as $name => $value) {
            $fields[] = ['h:'.$name, (string) $value];
        }
        foreach (array_slice($m->tags, 0, 3) as $t) {
            $fields[] = ['o:tag', $t['name'].':'.($t['value'] ?? '')];
        }

        $response = $this->call(function () use ($fields, $m) {
            $request = $this->client()->asMultipart();
            foreach ($m->attachments as $a) {
                $request->attach(! empty($a['content_id']) ? 'inline' : 'attachment', $a['content'], $a['content_id'] ?: $a['filename']);
            }

            $multipart = array_map(fn ($f) => ['name' => $f[0], 'contents' => $f[1]], $fields);

            return $request->post('/v3/'.$this->domain().'/messages', $multipart);
        });

        return new DriverResult(trim((string) $response->json('id'), '<>') ?: null, $response->json() ?? []);
    }

    public function test(): void
    {
        $this->call(fn () => $this->client()->get('/v3/domains/'.$this->domain()));
    }

    private function domain(): string
    {
        return rawurlencode($this->config['domain'] ?? '');
    }

    private function client(): PendingRequest
    {
        $base = ($this->config['region'] ?? 'us') === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';

        return Http::baseUrl($base)
            ->withBasicAuth('api', $this->config['api_key'] ?? '')
            ->acceptJson()
            ->timeout($this->timeout());
    }
}
