<?php

namespace App\Livewire;

use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Docs')]
class Docs extends Component
{
    public function render()
    {
        $base = rtrim(config('app.url'), '/');

        $snippets = [
            'curl' => <<<SH
curl -X POST {$base}/emails \\
  -H "Authorization: Bearer re_your_key" \\
  -H "Content-Type: application/json" \\
  -d '{
    "from": "Acme <hello@acme.com>",
    "to": ["user@example.com"],
    "subject": "Hello",
    "html": "<p>It works!</p>",
    "tags": [{ "name": "category", "value": "welcome" }]
  }'
SH,
            'node' => <<<JS
// npm install resend
// The Resend SDK reads RESEND_BASE_URL, so no code changes are needed:
//   RESEND_BASE_URL={$base}
import { Resend } from 'resend';

const resend = new Resend(process.env.RESEND_API_KEY);

const { data, error } = await resend.emails.send({
  from: 'Acme <hello@acme.com>',
  to: ['user@example.com'],
  subject: 'Hello',
  html: '<p>It works!</p>',
});
JS,
            'python' => <<<PY
# pip install resend
import resend

resend.api_key = "re_your_key"
resend.api_url = "{$base}"

resend.Emails.send({
    "from": "Acme <hello@acme.com>",
    "to": ["user@example.com"],
    "subject": "Hello",
    "html": "<p>It works!</p>",
})
PY,
            'php' => <<<PHP
// Laravel HTTP client (any PSR-18 client works the same way)
\$response = Http::withToken(env('MAILROUTE_KEY'))
    ->post('{$base}/emails', [
        'from' => 'Acme <hello@acme.com>',
        'to' => ['user@example.com'],
        'subject' => 'Hello',
        'html' => '<p>It works!</p>',
    ]);

\$id = \$response->json('id');
PHP,
        ];

        $endpoints = [
            ['POST', '/emails', 'Send one email. Returns {"id": "…"}.'],
            ['POST', '/emails/batch', 'Send up to 100 emails in one request. Returns {"data": [{"id": "…"}]}.'],
            ['GET', '/emails', 'List emails, newest first. Query: limit (max 100), after (email ID).'],
            ['GET', '/emails/{id}', 'Get one email with status, provider and last_event.'],
            ['PATCH', '/emails/{id}', 'Reschedule a scheduled email: {"scheduled_at": "…"}.'],
            ['POST', '/emails/{id}/cancel', 'Cancel a scheduled email.'],
        ];

        $fields = [
            ['from', 'string', 'Sender, "email@x.com" or "Name <email@x.com>". Falls back to the default from address.'],
            ['to', 'string | string[]', 'Recipients. Up to 50 across to, cc and bcc.'],
            ['subject', 'string', 'Required.'],
            ['html / text', 'string', 'At least one is required.'],
            ['cc, bcc, reply_to', 'string | string[]', 'Optional.'],
            ['headers', 'object', 'Custom headers, e.g. {"X-Entity-Ref-ID": "123"}.'],
            ['attachments', 'array', '{filename, content (base64) or path (URL), content_type, content_id}. 40MB total.'],
            ['tags', 'array', '{name, value}. Usable as a routing condition.'],
            ['scheduled_at', 'string', 'ISO 8601 date or a phrase such as "in 1 hour".'],
        ];

        $headers = [
            ['Authorization', 'Bearer re_… (required)'],
            ['Idempotency-Key', 'Repeat-safe sends: the same key returns the same email instead of sending twice.'],
            ['X-Route', 'Force a route by name or ID, skipping condition matching.'],
        ];

        return view('livewire.docs', compact('base', 'snippets', 'endpoints', 'fields', 'headers'));
    }
}
