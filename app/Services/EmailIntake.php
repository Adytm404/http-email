<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\ApiKey;
use App\Models\Email;
use App\Models\Setting;
use App\Support\SafeFetchException;
use App\Support\SafeFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Address;
use Throwable;

/**
 * Validates Resend-style payloads, stores them and queues delivery.
 */
class EmailIntake
{
    public const MAX_RECIPIENTS = 50;

    public const MAX_ATTACHMENT_BYTES = 40 * 1024 * 1024;

    /**
     * Validate and normalise one payload. $prefix is used for batch error paths ("0.to").
     *
     * @throws ValidationException
     */
    public function normalize(array $data, string $prefix = ''): array
    {
        $data['from'] ??= Setting::get('default_from');
        foreach (['to', 'cc', 'bcc', 'reply_to'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = [$data[$field]];
            }
        }

        $v = Validator::make($data, [
            'from' => ['required', 'string', 'max:320'],
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'string', 'max:320'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['string', 'max:320'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['string', 'max:320'],
            'reply_to' => ['nullable', 'array'],
            'reply_to.*' => ['string', 'max:320'],
            'subject' => ['required', 'string', 'max:998'],
            'html' => ['nullable', 'string', 'required_without:text'],
            'text' => ['nullable', 'string', 'required_without:html'],
            'headers' => ['nullable', 'array'],
            'headers.*' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*.name' => ['required', 'string', 'max:256'],
            'tags.*.value' => ['nullable', 'string', 'max:256'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.filename' => ['nullable', 'string', 'max:255'],
            'attachments.*.content' => ['nullable', 'string', 'required_without:attachments.*.path'],
            'attachments.*.path' => ['nullable', 'url', 'required_without:attachments.*.content'],
            'attachments.*.content_type' => ['nullable', 'string', 'max:255'],
            'attachments.*.content_id' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'string'],
        ], [
            'html.required_without' => 'Missing `html` or `text` field.',
            'text.required_without' => 'Missing `html` or `text` field.',
        ]);

        $v->after(function ($v) use ($data) {
            $count = count($data['to'] ?? []) + count($data['cc'] ?? []) + count($data['bcc'] ?? []);
            if ($count > self::MAX_RECIPIENTS) {
                $v->errors()->add('to', 'Too many recipients. Maximum is '.self::MAX_RECIPIENTS.'.');
            }
            foreach (['from' => [$data['from'] ?? null], 'to' => $data['to'] ?? [], 'cc' => $data['cc'] ?? [], 'bcc' => $data['bcc'] ?? [], 'reply_to' => $data['reply_to'] ?? []] as $field => $list) {
                foreach ((array) $list as $address) {
                    if (is_string($address) && ! $this->validAddress($address)) {
                        $v->errors()->add($field, "Invalid `{$field}` field. The email address needs to follow the `email@example.com` or `Name <email@example.com>` format.");
                        break;
                    }
                }
            }
            if (! empty($data['scheduled_at']) && $this->parseSchedule($data['scheduled_at']) === null) {
                $v->errors()->add('scheduled_at', 'Invalid `scheduled_at` field. Use an ISO 8601 date or a phrase like "in 1 hour".');
            }
        });

        if ($v->fails()) {
            $errors = $v->errors()->toArray();
            if ($prefix !== '') {
                $errors = collect($errors)->mapWithKeys(fn ($m, $k) => ["{$prefix}.{$k}" => $m])->all();
            }
            throw ValidationException::withMessages($errors);
        }

        $data['scheduled_at'] = ! empty($data['scheduled_at']) ? $this->parseSchedule($data['scheduled_at']) : null;

        return $data;
    }

    /**
     * Store a normalised payload and queue it. Returns the existing email when
     * the idempotency key was seen before for this API key.
     */
    public function create(array $data, ?ApiKey $key = null, ?string $idempotencyKey = null, ?string $forcedRoute = null): Email
    {
        if ($idempotencyKey !== null) {
            $existing = $this->findIdempotent($key, $idempotencyKey);
            if ($existing) {
                return $existing;
            }
        }

        try {
            return $this->store($data, $key, $idempotencyKey, $forcedRoute);
        } catch (UniqueConstraintViolationException $e) {
            // Two concurrent requests with the same Idempotency-Key: the other one won.
            return $this->findIdempotent($key, $idempotencyKey) ?? throw $e;
        }
    }

    private function findIdempotent(?ApiKey $key, ?string $idempotencyKey): ?Email
    {
        return Email::where('api_key_id', $key?->id)->where('idempotency_key', $idempotencyKey)->first();
    }

    private function store(array $data, ?ApiKey $key, ?string $idempotencyKey, ?string $forcedRoute): Email
    {

        $attachments = $this->resolveAttachments($data['attachments'] ?? []);
        $scheduled = $data['scheduled_at'] && $data['scheduled_at']->isFuture() ? $data['scheduled_at'] : null;

        $email = DB::transaction(function () use ($data, $key, $idempotencyKey, $forcedRoute, $attachments, $scheduled) {
            $email = Email::create([
                'api_key_id' => $key?->id,
                'from' => $data['from'],
                'to' => array_values($data['to']),
                'cc' => isset($data['cc']) ? array_values($data['cc']) : null,
                'bcc' => isset($data['bcc']) ? array_values($data['bcc']) : null,
                'reply_to' => isset($data['reply_to']) ? array_values($data['reply_to']) : null,
                'subject' => $data['subject'],
                'html' => $data['html'] ?? null,
                'text' => $data['text'] ?? null,
                'headers' => $data['headers'] ?? null,
                'tags' => isset($data['tags']) ? array_values(array_map(fn ($t) => ['name' => $t['name'], 'value' => (string) ($t['value'] ?? '')], $data['tags'])) : null,
                'status' => $scheduled ? 'scheduled' : 'queued',
                'scheduled_at' => $scheduled,
                'forced_route' => $forcedRoute,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($attachments as $i => $a) {
                $path = "attachments/{$email->id}/{$i}-".Str::slug(pathinfo($a['filename'], PATHINFO_FILENAME)).'.'.(pathinfo($a['filename'], PATHINFO_EXTENSION) ?: 'bin');
                Storage::disk('local')->put($path, $a['content']);
                $email->attachments()->create([
                    'filename' => $a['filename'],
                    'content_type' => $a['content_type'],
                    'content_id' => $a['content_id'],
                    'path' => $path,
                    'size' => strlen($a['content']),
                ]);
            }

            return $email;
        });

        $this->dispatch($email);

        return $email;
    }

    public function dispatch(Email $email): void
    {
        $job = new SendEmailJob($email->id);
        if ($email->scheduled_at && $email->scheduled_at->isFuture()) {
            $job->delay($email->scheduled_at);
        }
        dispatch($job)->afterCommit();
    }

    public function parseSchedule(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if (preg_match('/^in\s+(.+)$/i', $value, $m)) {
            $value = '+'.preg_replace(['/\bmins?\b/i', '/\bhrs?\b/i', '/\bsecs?\b/i'], ['minutes', 'hours', 'seconds'], $m[1]);
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function validAddress(string $address): bool
    {
        try {
            return filter_var(Address::create($address)->getAddress(), FILTER_VALIDATE_EMAIL) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Decode base64 content or download `path` URLs.
     *
     * @return list<array{filename: string, content: string, content_type: ?string, content_id: ?string}>
     *
     * @throws ValidationException
     */
    private function resolveAttachments(array $attachments): array
    {
        $out = [];
        $total = 0;

        foreach (array_values($attachments) as $i => $a) {
            if (! empty($a['content'])) {
                $content = base64_decode($a['content'], true);
                if ($content === false) {
                    throw ValidationException::withMessages(["attachments.{$i}.content" => 'Attachment content must be base64 encoded.']);
                }
            } else {
                try {
                    $fetched = app(SafeFetcher::class)->fetch($a['path'], max(0, self::MAX_ATTACHMENT_BYTES - $total));
                } catch (SafeFetchException $e) {
                    throw ValidationException::withMessages(["attachments.{$i}.path" => $e->getMessage()]);
                }
                $content = $fetched['body'];
                $a['content_type'] ??= $fetched['content_type'];
            }

            $total += strlen($content);
            if ($total > self::MAX_ATTACHMENT_BYTES) {
                throw ValidationException::withMessages(['attachments' => 'Attachments exceed the 40MB limit.']);
            }

            $filename = $a['filename'] ?? (isset($a['path']) ? basename(parse_url($a['path'], PHP_URL_PATH) ?: '') : '') ?: "attachment-{$i}";

            $out[] = [
                'filename' => $filename,
                'content' => $content,
                'content_type' => $a['content_type'] ?? null,
                'content_id' => $a['content_id'] ?? null,
            ];
        }

        return $out;
    }
}
