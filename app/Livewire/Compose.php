<?php

namespace App\Livewire;

use App\Models\Email;
use App\Models\RoutingRule;
use App\Models\Setting;
use App\Services\EmailIntake;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Send email')]
class Compose extends Component
{
    #[Url(as: 'from_email', except: '')]
    public string $copyOf = '';

    public string $from = '';

    public string $to = '';

    public string $cc = '';

    public string $bcc = '';

    public string $replyTo = '';

    public string $subject = '';

    public string $format = 'html';

    public string $html = '';

    public string $text = '';

    public string $tags = '';

    public string $route = '';

    public string $scheduleAt = '';

    public bool $showCopies = false;

    public function mount(): void
    {
        $this->authorize('send_emails');
        $this->from = (string) Setting::get('default_from');
        $this->html = "<h1>Hello!</h1>\n<p>Write your message here.</p>";

        if ($this->copyOf !== '' && preg_match('/^[0-9a-f-]{36}$/i', $this->copyOf) && auth()->user()->can('view_email_content')) {
            $source = Email::find($this->copyOf);
            if ($source) {
                $this->from = $source->from;
                $this->to = implode(', ', $source->to ?? []);
                $this->cc = implode(', ', $source->cc ?? []);
                $this->bcc = implode(', ', $source->bcc ?? []);
                $this->replyTo = implode(', ', $source->reply_to ?? []);
                $this->subject = $source->subject;
                $this->html = (string) $source->html;
                $this->text = (string) $source->text;
                $this->format = $source->html ? 'html' : 'text';
                $this->tags = collect($source->tags ?? [])->map(fn ($t) => $t['name'].(($t['value'] ?? '') !== '' ? ':'.$t['value'] : ''))->implode(', ');
                $this->showCopies = (bool) ($this->cc || $this->bcc || $this->replyTo);
            }
        }
    }

    public function send(EmailIntake $intake)
    {
        $this->authorize('send_emails');

        $list = fn (string $v) => array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $v))));
        $tags = collect($list($this->tags))->map(function ($t) {
            [$name, $value] = array_pad(explode(':', $t, 2), 2, '');

            return ['name' => trim($name), 'value' => trim($value)];
        })->filter(fn ($t) => $t['name'] !== '')->values()->all();

        $payload = array_filter([
            'from' => $this->from,
            'to' => $list($this->to),
            'cc' => $list($this->cc) ?: null,
            'bcc' => $list($this->bcc) ?: null,
            'reply_to' => $list($this->replyTo) ?: null,
            'subject' => $this->subject,
            'html' => $this->format === 'html' && trim($this->html) !== '' ? $this->html : null,
            'text' => trim($this->text) !== '' ? $this->text : null,
            'tags' => $tags ?: null,
            'scheduled_at' => $this->scheduleAt !== '' ? $this->scheduleAt : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        try {
            $data = $intake->normalize($payload);
        } catch (ValidationException $e) {
            $map = ['reply_to' => 'replyTo', 'scheduled_at' => 'scheduleAt'];
            foreach ($e->errors() as $field => $messages) {
                $key = $map[strtok($field, '.')] ?? strtok($field, '.');
                $this->addError(property_exists($this, $key) ? $key : 'to', $messages[0]);
            }

            return null;
        }

        $email = $intake->create($data, null, null, $this->route !== '' ? $this->route : null);

        session()->flash('toast', $email->status === 'scheduled' ? 'Email scheduled' : 'Email queued for delivery');

        return $this->redirectRoute('emails.show', $email, navigate: true);
    }

    public function render()
    {
        return view('livewire.compose', [
            'routes' => RoutingRule::where('is_active', true)->orderBy('priority')->get(),
        ]);
    }
}
