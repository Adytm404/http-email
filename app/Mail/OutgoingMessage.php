<?php

namespace App\Mail;

use App\Models\Email;
use App\Models\Provider;
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

    /**
     * The message as it is sent through one provider. A provider with a default sender replaces the From
     * (keeping the display name of the original when the default has none) and the original sender
     * becomes the Reply-To, so replies still reach whoever the email was written for.
     */
    public function forProvider(Provider $provider): self
    {
        $default = trim((string) $provider->from_address);
        if ($default === '') {
            return $this;
        }

        try {
            $new = Address::create($default);
            $original = Address::create($this->from);
        } catch (\Throwable) {
            return $this;
        }

        $name = $new->getName() !== '' ? $new->getName() : $original->getName();
        $from = $name !== '' ? (new Address($new->getAddress(), $name))->toString() : $new->getAddress();
        $differs = strcasecmp($new->getAddress(), $original->getAddress()) !== 0;

        return new self(
            id: $this->id,
            from: $from,
            to: $this->to,
            subject: $this->subject,
            html: $this->html,
            text: $this->text,
            cc: $this->cc,
            bcc: $this->bcc,
            replyTo: $this->replyTo === [] && $differs ? [$this->from] : $this->replyTo,
            headers: $this->headers,
            tags: $this->tags,
            attachments: $this->attachments,
        );
    }

    /** Split "Name <a@b.c>" into ['email' => ..., 'name' => ...]. */
    public static function parseAddress(string $address): array
    {
        $a = Address::create($address);

        return array_filter(['email' => $a->getAddress(), 'name' => $a->getName()], fn ($v) => $v !== '');
    }
}
