<?php

namespace App\Mail;

use App\Models\Email;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;

/**
 * Provider-neutral message handed to every driver.
 */
final class OutgoingMessage
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  list<string>  $replyTo
     * @param  array<string, string>  $headers
     * @param  list<array{name: string, value: string}>  $tags
     * @param  list<array{filename: string, content: string, content_type: ?string, content_id: ?string}>  $attachments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly array $to,
        public readonly string $subject,
        public readonly ?string $html = null,
        public readonly ?string $text = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $replyTo = [],
        public readonly array $headers = [],
        public readonly array $tags = [],
        public readonly array $attachments = [],
    ) {}

    public static function fromEmail(Email $email): self
    {
        $attachments = $email->attachments->map(fn ($a) => [
            'filename' => $a->filename,
            'content' => Storage::disk('local')->get($a->path) ?? '',
            'content_type' => $a->content_type,
            'content_id' => $a->content_id,
        ])->all();

        return new self(
            id: $email->id,
            from: $email->from,
            to: $email->to ?? [],
            subject: $email->subject,
            html: $email->html,
            text: $email->text,
            cc: $email->cc ?? [],
            bcc: $email->bcc ?? [],
            replyTo: $email->reply_to ?? [],
            headers: $email->headers ?? [],
            tags: $email->tags ?? [],
            attachments: $attachments,
        );
    }

    /** Split "Name <a@b.c>" into ['email' => ..., 'name' => ...]. */
    public static function parseAddress(string $address): array
    {
        $a = Address::create($address);

        return array_filter(['email' => $a->getAddress(), 'name' => $a->getName()], fn ($v) => $v !== '');
    }
}
