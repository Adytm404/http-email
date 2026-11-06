<?php

namespace Database\Seeders;

use App\Models\ApiKey;
use App\Models\Email;
use App\Models\EmailAttempt;
use App\Models\Provider;
use App\Models\Role;
use App\Models\RoutingRule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fills a throwaway database with realistic demo data (used for the README screenshots).
 *
 *   DB_SEARCH_PATH=demo php artisan db:seed --class=DemoSeeder
 *
 * It wipes providers, routes, keys, emails and non-owner users first, so it refuses to
 * run in production or against the default `public` schema.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() || config('database.connections.pgsql.search_path') === 'public') {
            throw new RuntimeException('DemoSeeder wipes data. Run it against a separate schema (DB_SEARCH_PATH) outside production.');
        }

        mt_srand(20261106);

        DB::transaction(function () {
            DB::table('email_attempts')->delete();
            DB::table('email_attachments')->delete();
            DB::table('emails')->delete();
            DB::table('provider_routing_rule')->delete();
            DB::table('routing_rules')->delete();
            DB::table('provider_usage')->delete();
            DB::table('providers')->delete();
            DB::table('api_keys')->delete();

            $this->users();
            $providers = $this->providers();
            $this->routes($providers);
            $keys = $this->keys();
            $this->emails($providers, $keys);
        });
    }

    private function users(): void
    {
        $password = env('DEMO_PASSWORD', 'demo-password-123');
        $role = fn (string $name) => Role::where('name', $name)->value('id');

        foreach ([
            ['Nadia Putri', 'nadia@lumen.test', 'Owner', true, 2],
            ['Raka Pratama', 'raka@lumen.test', 'Admin', true, 30],
            ['Sinta Wulandari', 'sinta@lumen.test', 'Developer', true, 240],
            ['Dimas Saputra', 'dimas@lumen.test', 'Viewer', true, 4320],
            ['Ayu Lestari', 'ayu@lumen.test', 'Developer', false, 20160],
        ] as [$name, $email, $roleName, $active, $minutesAgo]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => $password,
                'role_id' => $role($roleName),
                'is_active' => $active,
                'last_login_at' => now()->subMinutes($minutesAgo),
            ]);
        }

        if (! Role::where('name', 'Support')->exists()) {
            Role::create(['name' => 'Support', 'description' => 'Read the send log and retry failed emails.', 'permissions' => ['view_dashboard', 'view_emails', 'view_email_content', 'manage_emails']]);
        }
    }

    /** @return array<string, Provider> */
    private function providers(): array
    {
        $make = fn (array $a) => Provider::create($a);

        return [
            'smtp' => $make(['name' => 'Primary SMTP', 'type' => 'smtp', 'config' => ['host' => 'mail.lumen.test', 'port' => 587, 'encryption' => 'starttls', 'username' => 'noreply@lumen.test'], 'priority' => 10, 'weight' => 60, 'limit_per_minute' => 300, 'limit_per_day' => 50000]),
            'resend' => $make(['name' => 'Resend', 'type' => 'resend', 'config' => ['api_key' => 're_demo'], 'priority' => 20, 'weight' => 25, 'limit_per_day' => 3000]),
            'sendgrid' => $make(['name' => 'SendGrid', 'type' => 'sendgrid', 'config' => ['api_key' => 'SG.demo'], 'priority' => 30, 'weight' => 15, 'limit_per_hour' => 2000]),
            'mailgun' => $make(['name' => 'Mailgun EU', 'type' => 'mailgun', 'config' => ['api_key' => 'demo', 'domain' => 'mg.lumen.test', 'region' => 'eu'], 'priority' => 40, 'weight' => 100,
                'health' => 'down', 'consecutive_failures' => 3, 'cooldown_until' => now()->addMinutes(4), 'last_error' => 'HTTP 503: Service temporarily unavailable']),
            'gmail' => $make(['name' => 'Gmail backup', 'type' => 'smtp', 'config' => ['host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'ops@lumen.test'], 'priority' => 50, 'weight' => 100, 'limit_per_day' => 450]),
        ];
    }

    private function routes(array $p): void
    {
        $rule = function (string $name, int $priority, string $strategy, ?array $conditions, array $members, bool $default = false) {
            $r = RoutingRule::create(['name' => $name, 'priority' => $priority, 'strategy' => $strategy, 'conditions' => $conditions, 'is_default' => $default]);
            foreach ($members as $i => [$provider, $weight]) {
                $r->providers()->attach($provider->id, ['priority' => $i + 1, 'weight' => $weight]);
            }
        };

        $rule('Transactional', 1, 'failover', ['tag' => 'type:transactional'], [[$p['resend'], 100], [$p['smtp'], 100], [$p['gmail'], 100]]);
        $rule('Gmail & Yahoo recipients', 2, 'failover', ['to_domain' => 'gmail.com, yahoo.com'], [[$p['smtp'], 100], [$p['sendgrid'], 100]]);
        $rule('Newsletters', 3, 'weighted', ['from_domain' => 'news.lumen.test'], [[$p['sendgrid'], 70], [$p['mailgun'], 30]]);
        $rule('Default', 4, 'weighted', null, [[$p['smtp'], 60], [$p['resend'], 25], [$p['sendgrid'], 15]], true);
    }

    private function keys(): array
    {
        $keys = [];
        foreach ([['Storefront (production)', 'sending_access', 1], ['Billing service', 'full_access', 12], ['Mobile app', 'sending_access', 90], ['Staging', 'full_access', 4000]] as [$name, $perm, $minutes]) {
            [$key] = ApiKey::generate($name, $perm);
            $key->forceFill(['last_used_at' => now()->subMinutes($minutes)])->save();
            $keys[] = $key;
        }
        [$old] = ApiKey::generate('Old checkout', 'sending_access');
        $old->forceFill(['revoked_at' => now()->subDays(9)])->save();

        return $keys;
    }

    private function emails(array $p, array $keys): void
    {
        $firstNames = ['budi', 'siti', 'andi', 'rina', 'agus', 'dewi', 'fajar', 'maya', 'yoga', 'putri', 'hendra', 'lina', 'reza', 'indah', 'bayu', 'tari', 'kevin', 'sarah', 'joko', 'nina'];
        $domains = ['gmail.com', 'gmail.com', 'gmail.com', 'yahoo.com', 'outlook.com', 'icloud.com', 'company.co.id', 'proton.me', 'hey.com'];
        $templates = [
            ['Welcome to Lumen, {name}!', 'type:transactional', 'Lumen <hello@lumen.test>'],
            ['Your order #{n} is confirmed', 'type:transactional', 'Lumen Store <orders@lumen.test>'],
            ['Your order #{n} has shipped', 'type:transactional', 'Lumen Store <orders@lumen.test>'],
            ['Reset your password', 'type:transactional', 'Lumen <security@lumen.test>'],
            ['Your sign-in code is {code}', 'type:transactional', 'Lumen <security@lumen.test>'],
            ['Invoice INV-{n} for November', 'type:billing', 'Lumen Billing <billing@lumen.test>'],
            ['Payment received — thank you', 'type:billing', 'Lumen Billing <billing@lumen.test>'],
            ['This week at Lumen: 5 new features', 'type:newsletter', 'Lumen News <digest@news.lumen.test>'],
            ['Flash sale: 40% off until midnight', 'type:newsletter', 'Lumen News <promo@news.lumen.test>'],
        ];
        $errors = [
            ['550', '550 5.1.1 The email account that you tried to reach does not exist.'],
            ['552', '552 5.2.2 Mailbox full'],
            ['422', 'The to field contains an invalid email address.'],
        ];
        $routes = RoutingRule::pluck('id', 'name');
        $weighted = [[$p['smtp'], 60], [$p['resend'], 25], [$p['sendgrid'], 15]];

        for ($day = 29; $day >= 0; $day--) {
            $date = now()->subDays($day);
            $weekend = $date->isWeekend();
            $count = (int) round((60 + (29 - $day) * 4.5) * ($weekend ? 0.6 : 1) + mt_rand(-12, 12));
            if ($day === 0) {
                $count = (int) round($count * min(1, now()->hour / 20));
            }

            for ($i = 0; $i < $count; $i++) {
                $created = $date->copy()->setTime(mt_rand(6, 23), mt_rand(0, 59), mt_rand(0, 59));
                if ($created->isFuture()) {
                    $created = now()->subMinutes(mt_rand(1, 50));
                }
                [$subject, $tag, $from] = $templates[array_rand($templates)];
                $name = $firstNames[array_rand($firstNames)];
                $domain = $domains[array_rand($domains)];
                $to = $name.mt_rand(1, 99).'@'.$domain;
                $subject = strtr($subject, ['{name}' => ucfirst($name), '{n}' => (string) mt_rand(10421, 99870), '{code}' => (string) mt_rand(100000, 999999)]);

                $roll = mt_rand(1, 1000);
                $status = match (true) {
                    $day === 0 && $roll > 985 => 'queued',
                    $day === 0 && $roll > 975 => 'scheduled',
                    $roll > 975 => 'failed',
                    $roll > 970 => 'canceled',
                    default => 'sent',
                };

                [$tagName, $tagValue] = explode(':', $tag);
                $routeName = match (true) {
                    $tagValue === 'transactional' => 'Transactional',
                    in_array($domain, ['gmail.com', 'yahoo.com'], true) => 'Gmail & Yahoo recipients',
                    $tagValue === 'newsletter' => 'Newsletters',
                    default => 'Default',
                };
                $provider = match ($routeName) {
                    'Transactional' => mt_rand(1, 20) === 1 ? $p['smtp'] : $p['resend'],
                    'Gmail & Yahoo recipients' => $p['smtp'],
                    'Newsletters' => $p['sendgrid'],
                    default => $this->pick($weighted),
                };

                $email = Email::create([
                    'api_key_id' => $keys[$tagValue === 'billing' ? 1 : ($tagValue === 'newsletter' ? 0 : mt_rand(0, 2))]->id,
                    'from' => $from,
                    'to' => [$to],
                    'subject' => $subject,
                    'html' => $this->html($subject, ucfirst($name)),
                    'text' => "Hi ".ucfirst($name).",\n\n{$subject}.\n\n— The Lumen team",
                    'tags' => [['name' => $tagName, 'value' => $tagValue]],
                    'status' => $status,
                    'routing_rule_id' => $status === 'queued' ? null : $routes[$routeName],
                    'provider_id' => $status === 'sent' ? $provider->id : null,
                    'provider_message_id' => $status === 'sent' ? bin2hex(random_bytes(8)).'@lumen.test' : null,
                    'attempts' => in_array($status, ['queued', 'scheduled'], true) ? 0 : 1,
                    'scheduled_at' => $status === 'scheduled' ? now()->addHours(mt_rand(1, 6)) : null,
                    'sent_at' => $status === 'sent' ? $created->copy()->addMilliseconds(mt_rand(600, 4200)) : null,
                    'failed_reason' => null,
                ]);
                $email->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly();

                if ($status === 'sent') {
                    // A few emails show a failover: Mailgun/SendGrid failed first, then the next provider took it.
                    if (mt_rand(1, 25) === 1) {
                        $this->attempt($email, $p['mailgun'], 'failed', $created, '503', 'HTTP 503: Service temporarily unavailable');
                    }
                    $this->attempt($email, $provider, 'sent', $created);
                } elseif ($status === 'failed') {
                    [$code, $message] = $errors[array_rand($errors)];
                    $this->attempt($email, $provider, 'failed', $created, $code, $message);
                    $email->forceFill(['failed_reason' => "{$provider->name}: {$message}"])->saveQuietly();
                }
            }
        }
    }

    private function attempt(Email $email, Provider $provider, string $status, $at, ?string $code = null, ?string $message = null): void
    {
        EmailAttempt::create([
            'email_id' => $email->id,
            'provider_id' => $provider->id,
            'status' => $status,
            'error_code' => $code,
            'error_message' => $message,
            'duration_ms' => mt_rand(180, 2400),
        ])->forceFill(['created_at' => $at])->saveQuietly();
    }

    private function pick(array $weighted): Provider
    {
        $roll = mt_rand(1, array_sum(array_column($weighted, 1)));
        foreach ($weighted as [$provider, $weight]) {
            if (($roll -= $weight) <= 0) {
                return $provider;
            }
        }

        return $weighted[0][0];
    }

    private function html(string $subject, string $name): string
    {
        $subject = e($subject);

        return <<<HTML
<div style="background:#f4f6fb;padding:32px 0;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif">
  <div style="max-width:520px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e9f2">
    <div style="background:linear-gradient(135deg,#1d4ed8,#3b82f6);padding:28px 32px;color:#fff">
      <div style="font-size:14px;opacity:.8;letter-spacing:.08em">LUMEN</div>
      <div style="font-size:24px;font-weight:600;margin-top:8px">{$subject}</div>
    </div>
    <div style="padding:28px 32px;color:#0f172a;font-size:15px;line-height:1.6">
      <p style="margin:0 0 14px">Hi {$name},</p>
      <p style="margin:0 0 20px">Thanks for being with Lumen. Everything you need is one click away.</p>
      <a href="https://lumen.test/account" style="display:inline-block;background:#1677ff;color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:600">Open your account</a>
      <p style="margin:24px 0 0;color:#64748b;font-size:13px">— The Lumen team</p>
    </div>
  </div>
</div>
HTML;
    }
}
