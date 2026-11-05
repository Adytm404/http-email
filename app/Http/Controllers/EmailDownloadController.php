<?php

namespace App\Http\Controllers;

use App\Livewire\Emails\Index;
use App\Mail\Drivers\SmtpDriver;
use App\Mail\OutgoingMessage;
use App\Models\Email;
use App\Models\EmailAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailDownloadController extends Controller
{
    /** The email as an RFC 822 .eml file, openable in any mail client. */
    public function eml(Email $email): Response
    {
        $email->load('attachments');
        $mime = (new SmtpDriver([]))->buildMime(OutgoingMessage::fromEmail($email));
        $mime->date($email->created_at->toDateTimeImmutable());
        if ($email->provider_message_id) {
            $mime->getHeaders()->addIdHeader('Message-ID', trim($email->provider_message_id, '<>'));
        }

        return response($mime->toString(), 200, [
            'Content-Type' => 'message/rfc822',
            'Content-Disposition' => 'attachment; filename="'.Str::slug(Str::limit($email->subject, 60, '')) .'-'.substr($email->id, 0, 8).'.eml"',
        ]);
    }

    public function attachment(Email $email, EmailAttachment $attachment): Response
    {
        abort_unless($attachment->email_id === $email->id, 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        // Always as a download, never rendered inline, so HTML/SVG attachments cannot run in our origin.
        return Storage::disk('local')->download($attachment->path, $attachment->filename, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The send log with the same filters as the Emails page, as CSV. */
    public function export(Request $request): StreamedResponse
    {
        $query = Index::applyFilters(Email::query()->with('provider'), $request->only('search', 'status', 'provider', 'range'))
            ->orderByDesc('created_at');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'status', 'from', 'to', 'cc', 'bcc', 'subject', 'provider', 'provider_message_id', 'attempts', 'sent_at', 'failed_reason', 'tags']);
            $query->chunk(500, function ($emails) use ($out) {
                foreach ($emails as $e) {
                    fputcsv($out, array_map([self::class, 'safeCell'], [
                        $e->id,
                        $e->created_at?->toIso8601String(),
                        $e->status,
                        $e->from,
                        implode(', ', $e->to ?? []),
                        implode(', ', $e->cc ?? []),
                        implode(', ', $e->bcc ?? []),
                        $e->subject,
                        $e->provider?->name,
                        $e->provider_message_id,
                        $e->attempts,
                        $e->sent_at?->toIso8601String(),
                        $e->failed_reason,
                        collect($e->tags ?? [])->map(fn ($t) => $t['name'].':'.($t['value'] ?? ''))->implode(' '),
                    ]));
                }
            });
            fclose($out);
        }, 'emails-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /** Stop spreadsheet apps from treating a cell as a formula. */
    public static function safeCell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
