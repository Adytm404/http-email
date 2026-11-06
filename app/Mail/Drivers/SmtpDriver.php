<?php

namespace App\Mail\Drivers;

use App\Mail\DriverException;
use App\Mail\DriverResult;
use App\Mail\Drivers\Contracts\EmailDriver;
use App\Mail\OutgoingMessage;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email as MimeEmail;
use Symfony\Component\Mime\Part\DataPart;

class SmtpDriver implements EmailDriver
{
    /**
     * Config keys: host, port, encryption, username, password, timeout, verify_peer.
     * encryption: "ssl" = implicit TLS (465), "starttls" = STARTTLS required, "none" = plain text.
     */
    public function __construct(private readonly array $config) {}

    public function send(OutgoingMessage $message): DriverResult
    {
        $transport = $this->transport();

        try {
            $sent = $transport->send($this->buildMime($message));
        } catch (TransportExceptionInterface $e) {
            throw $this->classify($e);
        } finally {
            $this->stop($transport);
        }

        return new DriverResult($sent?->getMessageId(), ['debug' => mb_substr((string) $sent?->getDebug(), -2000)]);
    }

    public function test(): void
    {
        $transport = $this->transport();

        try {
            $transport->start();
        } catch (TransportExceptionInterface $e) {
            throw $this->classify($e);
        } finally {
            $this->stop($transport);
        }
    }

    private function transport(): EsmtpTransport
    {
        $encryption = $this->config['encryption'] ?? 'starttls';
        $port = (int) ($this->config['port'] ?? ($encryption === 'ssl' ? 465 : 587));

        $transport = new EsmtpTransport($this->config['host'], $port, $encryption === 'ssl');
        $transport->setAutoTls($encryption !== 'none');
        $transport->setRequireTls($encryption === 'starttls');

        if (! empty($this->config['username'])) {
            $transport->setUsername($this->config['username']);
            $transport->setPassword($this->config['password'] ?? '');
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) ($this->config['timeout'] ?? 20));
            if (($this->config['verify_peer'] ?? true) === false) {
                $stream->setStreamOptions(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
            }
        }

        return $transport;
    }

    private function stop(EsmtpTransport $transport): void
    {
        try {
            $transport->stop();
        } catch (\Throwable) {
            // connection already gone
        }
    }

    public function buildMime(OutgoingMessage $m): MimeEmail
    {
        $mime = (new MimeEmail)
            ->from(Address::create($m->from))
            ->to(...array_map(Address::create(...), $m->to))
            ->subject($m->subject);

        if ($m->cc) {
            $mime->cc(...array_map(Address::create(...), $m->cc));
        }
        if ($m->bcc) {
            $mime->bcc(...array_map(Address::create(...), $m->bcc));
        }
        if ($m->replyTo) {
            $mime->replyTo(...array_map(Address::create(...), $m->replyTo));
        }
        if ($m->html !== null) {
            $mime->html($m->html);
        }
        if ($m->text !== null) {
            $mime->text($m->text);
        }

        foreach ($m->headers as $name => $value) {
            $mime->getHeaders()->addTextHeader($name, (string) $value);
        }
        $mime->getHeaders()->addTextHeader('X-Mailroute-Id', $m->id);

        foreach ($m->attachments as $a) {
            $part = new DataPart($a['content'], $a['filename'], $a['content_type'] ?? null);
            if (! empty($a['content_id'])) {
                $part->asInline()->setContentId($a['content_id']);
            }
            $mime->addPart($part);
        }

        return $mime;
    }

    private function classify(TransportExceptionInterface $e): DriverException
    {
        $result = self::classifyReply((int) $e->getCode(), $e->getMessage());

        return $result->providerFault
            ? DriverException::providerDown($result->getMessage(), $result->errorCode, previous: $e)
            : $result;
    }

    /**
     * Decide what an SMTP error means for routing.
     *
     * The basic reply code alone is not enough: Gmail answers "550 5.4.5 Daily user
     * sending limit exceeded", which is the sender's quota, not a bad recipient.
     * The enhanced status code (RFC 3463, "class.subject.detail") tells them apart.
     */
    public static function classifyReply(int $code, string $message): DriverException
    {
        $msg = mb_substr($message, 0, 1000);
        $enhanced = preg_match('/\b[245]\.(\d{1,3})\.(\d{1,3})\b/', $msg, $m) ? [(int) $m[1], (int) $m[2]] : null;

        // x.1.x (address) and x.2.x (mailbox, e.g. "5.2.2 mailbox over quota") are about the
        // recipient, whatever the wording: a 5xx stops here, a 4xx is retried later.
        if ($enhanced !== null && in_array($enhanced[0], [1, 2], true) && $code >= 400) {
            return $code >= 500
                ? DriverException::permanent($msg, (string) $code)
                : DriverException::transient($msg, (string) $code);
        }

        // Sending quota or rate limit of this account: rest the provider and use the next one.
        // x.4.5 = "mail system congestion / quota", plus the wording providers use for limits.
        if (($enhanced === [4, 5]) || preg_match('/(sending|daily|hourly|rate)\s+limit|limit\s+exceeded|quota\s+exceeded|over\s+quota|too many (messages|emails|connections)/i', $msg)) {
            $daily = (bool) preg_match('/daily|24.?hour|per day/i', $msg);

            return DriverException::quota($msg, (string) ($code ?: 'quota'), $daily ? 3600 : 900);
        }

        // 0 = could not connect / TLS failure; 421 = service unavailable;
        // 530/534/535/504 = authentication problems — all mean the provider is unusable.
        if ($code === 0 || in_array($code, [421, 504, 530, 534, 535], true)) {
            return DriverException::providerDown($msg, $code ? (string) $code : 'connection');
        }

        // 550-553 about the recipient's address or mailbox (x.1.x, x.2.x) or the message
        // size (x.3.4): every provider would get the same answer, so stop here.
        if (in_array($code, [550, 551, 552, 553], true)) {
            $aboutRecipient = $enhanced === null || in_array($enhanced[0], [1, 2], true) || $enhanced === [3, 4];
            if ($aboutRecipient) {
                return DriverException::permanent($msg, (string) $code);
            }
        }

        // Anything else (policy 5.7.x such as an unverified sender at this provider,
        // greylisting, 554 rejections): worth trying the next provider.
        return DriverException::transient($msg, (string) $code);
    }
}
