<?php

use App\Models\Email;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Routing\QuotaManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

Artisan::command('emails:prune', function (QuotaManager $quota) {
    $days = (int) Setting::get('retention_days');
    $cutoff = now()->subDays($days);
    $deleted = 0;

    Email::where('created_at', '<', $cutoff)
        ->whereNotIn('status', ['queued', 'sending', 'scheduled'])
        ->select('id')
        ->chunkById(500, function ($emails) use (&$deleted) {
            foreach ($emails as $email) {
                Storage::disk('local')->deleteDirectory("attachments/{$email->id}");
            }
            $deleted += Email::whereIn('id', $emails->pluck('id'))->delete();
        });

    $quota->prune();
    $this->info("Deleted {$deleted} emails older than {$days} days.");
})->purpose('Delete emails, attempts and attachments past the retention period');

Artisan::command('app:create-admin {email?} {--name=Admin}', function () {
    $email = $this->argument('email') ?? text('Email', required: true);
    $pass = password('Password (min 10 characters)', required: true, validate: fn ($v) => strlen($v) < 10 ? 'At least 10 characters.' : null);

    User::updateOrCreate(['email' => $email], [
        'name' => $this->option('name'),
        'password' => $pass,
        'role_id' => Role::owner()?->id,
        'is_active' => true,
    ]);
    $this->info("Admin {$email} saved.");
})->purpose('Create or reset a dashboard admin account');

Artisan::command('app:captcha-off', function () {
    Setting::put('captcha_mode', 'off');
    $this->info('Sign-in captcha turned off. Keys are kept; turn it back on under Settings.');
})->purpose('Turn off the sign-in reCAPTCHA (e.g. after configuring wrong keys)');

Schedule::command('emails:prune')->dailyAt('03:00');

// Shared hosting without a process manager: let cron start a worker every
// minute that polls the queue for ~55 seconds, so mail still goes out quickly.
if (config('mailroute.queue_via_scheduler')) {
    Schedule::command('queue:work --queue=emails,default --sleep=2 --max-time=55 --tries=4')
        ->everyMinute()
        ->withoutOverlapping(2)
        ->runInBackground();
}
