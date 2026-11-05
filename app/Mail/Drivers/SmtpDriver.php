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
        $code = (int) $e->getCode();
        $msg = mb_substr($e->getMessage(), 0, 1000);

        // 0 = could not connect / TLS failure; 421 = service unavailable;
        // 530/534/535/504 = authentication problems — all mean the provider is unusable.
        if ($code === 0 || in_array($code, [421, 504, 530, 534, 535], true)) {
            return DriverException::providerDown($msg, $code ? (string) $code : 'connection', previous: $e);
        }

        // 550-553: mailbox unavailable / bad address / too large. The message
        // itself is the problem, so other providers would reject it too.
        if (in_array($code, [550, 551, 552, 553], true)) {
            return DriverException::permanent($msg, (string) $code);
        }

        // Other 4xx/5xx (greylisting, 554 policy rejections such as an
        // unverified sender at this provider): worth trying the next provider.
        return DriverException::transient($msg, (string) $code);
    }
}
