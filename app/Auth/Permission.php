<?php

namespace App\Auth;

/**
 * Every ability a role can be granted. Keys are used as Gate abilities
 * (e.g. `can:manage_providers`), labels and groups drive the role editor.
 */
final class Permission
{
    public const ALL = [
        'view_dashboard' => ['Overview', 'See the overview page and traffic charts.', 'General'],
        'view_emails' => ['View send log', 'See emails, recipients, status and delivery attempts.', 'Emails'],
        'view_email_content' => ['Read email content', 'Open email bodies, attachments list and download .eml files.', 'Emails'],
        'send_emails' => ['Compose & send', 'Send emails from the dashboard.', 'Emails'],
        'manage_emails' => ['Retry & cancel', 'Retry failed emails and cancel scheduled ones.', 'Emails'],
        'export_emails' => ['Export CSV', 'Download the send log as CSV.', 'Emails'],
        'manage_providers' => ['Manage providers', 'Add, edit, test and delete providers.', 'Delivery'],
        'manage_routing' => ['Manage routing', 'Create and edit routing rules.', 'Delivery'],
        'manage_api_keys' => ['Manage API keys', 'Create and revoke API keys.', 'Delivery'],
        'manage_settings' => ['Manage settings', 'Change default sender, failover and retention.', 'Administration'],
        'manage_users' => ['Manage users', 'Add, edit, deactivate and remove users.', 'Administration'],
        'manage_roles' => ['Manage roles', 'Create roles and choose their permissions.', 'Administration'],
    ];

    /** @return array<string, array<string, array{0: string, 1: string, 2: string}>> */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::ALL as $key => $meta) {
            $out[$meta[2]][$key] = $meta;
        }

        return $out;
    }

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $key): string
    {
        return self::ALL[$key][0] ?? $key;
    }
}
