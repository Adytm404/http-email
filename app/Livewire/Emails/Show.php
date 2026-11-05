<?php

namespace App\Livewire\Emails;

use App\Models\Email;
use App\Services\EmailIntake;
use Livewire\Component;

class Show extends Component
{
    public Email $email;

    public function mount(): void
    {
        $this->authorize('view_emails');
    }

    public function retry(EmailIntake $intake): void
    {
        $this->authorize('manage_emails');
        if (! in_array($this->email->status, ['failed', 'canceled'], true)) {
            return;
        }

        $this->email->forceFill(['status' => 'queued', 'failed_reason' => null, 'scheduled_at' => null])->save();
        $intake->dispatch($this->email);
        $this->dispatch('toast', message: 'Queued for another attempt');
    }

    public function cancel(): void
    {
        $this->authorize('manage_emails');
        if ($this->email->status === 'scheduled') {
            $this->email->forceFill(['status' => 'canceled'])->save();
            $this->dispatch('toast', message: 'Scheduled email canceled');
        }
    }

    public function render()
    {
        $this->email->refresh()->load(['attemptLogs.provider', 'attachments', 'provider', 'routingRule', 'apiKey']);

        return view('livewire.emails.show')->title($this->email->subject);
    }
}
