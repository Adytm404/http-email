<?php

namespace Tests\Unit;

use App\Mail\Drivers\SmtpDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmtpErrorClassificationTest extends TestCase
{
    public static function replies(): array
    {
        return [
            // [code, message, expected kind]
            'gmail daily limit' => [550, 'Expected response code "354" but got code "550", with message "550-5.4.5 Daily user sending limit exceeded. For more information on Gmail sending limits"', 'quota'],
            'gmail rate limit 4xx' => [421, '421-4.7.28 Our system has detected an unusual rate of unsolicited mail. Rate limit exceeded', 'quota'],
            'generic quota' => [452, '452 4.4.5 Insufficient system storage, quota exceeded', 'quota'],
            'unknown mailbox' => [550, '550-5.1.1 The email account that you tried to reach does not exist.', 'permanent'],
            'mailbox full' => [552, '552-5.2.2 The email account that you tried to reach is over quota', 'permanent'],
            'bare 550' => [550, '550 Requested action not taken: mailbox unavailable', 'permanent'],
            'message too big' => [552, '552-5.3.4 Your message exceeded Google\'s message size limits', 'permanent'],
            'sender not allowed' => [553, '553 5.7.1 Sender address rejected: not owned by user', 'transient'],
            'dmarc policy' => [550, '550-5.7.26 Unauthenticated email from lumen.test is not accepted', 'transient'],
            'auth failed' => [535, '535-5.7.8 Username and Password not accepted', 'down'],
            'cannot connect' => [0, 'Connection could not be established with host "smtp.example.com:587"', 'down'],
            'greylisted' => [451, '451 4.7.1 Greylisted, please try again later', 'transient'],
        ];
    }

    #[DataProvider('replies')]
    public function test_smtp_replies_are_classified(int $code, string $message, string $expected): void
    {
        $e = SmtpDriver::classifyReply($code, $message);

        $kind = match (true) {
            $e->pauseSeconds !== null => 'quota',
            ! $e->retryable => 'permanent',
            $e->providerFault => 'down',
            default => 'transient',
        };

        $this->assertSame($expected, $kind, $message);
    }

    public function test_daily_limits_pause_longer_than_short_ones(): void
    {
        $this->assertSame(3600, SmtpDriver::classifyReply(550, '550-5.4.5 Daily user sending limit exceeded.')->pauseSeconds);
        $this->assertSame(900, SmtpDriver::classifyReply(421, '421 4.7.0 Rate limit exceeded, try again later')->pauseSeconds);
    }
}
