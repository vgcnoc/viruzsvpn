<?php

namespace App\Services;

use App\Models\EmailSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class EmailDelivery
{
    public function details(): array
    {
        $setting = EmailSetting::first();

        return [
            'host' => $setting?->host, 'port' => $setting?->port ?? 587, 'encryption' => $setting?->encryption ?? 'tls',
            'username' => $setting?->username, 'from_address' => $setting?->from_address, 'from_name' => $setting?->from_name,
            'is_enabled' => (bool) $setting?->is_enabled, 'has_password' => (bool) $setting?->encrypted_password,
            'ready' => $this->isReady($setting),
        ];
    }

    public function isReady(?EmailSetting $setting = null): bool
    {
        $setting ??= EmailSetting::first();

        return (bool) ($setting?->is_enabled && $setting->host && $setting->port && $setting->username
            && $setting->encrypted_password && $setting->from_address);
    }

    public function configure(): void
    {
        $setting = EmailSetting::first();
        // Keep Laravel's environment mailer for local development and test
        // suites until an administrator enables a database SMTP profile.
        if (! $setting) {
            return;
        }
        if (! $this->isReady($setting)) {
            throw new RuntimeException('Email verification delivery is not configured.');
        }
        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.transport', 'smtp');
        Config::set('mail.mailers.smtp.host', $setting->host);
        Config::set('mail.mailers.smtp.port', $setting->port);
        Config::set('mail.mailers.smtp.encryption', $setting->encryption === 'none' ? null : $setting->encryption);
        Config::set('mail.mailers.smtp.username', $setting->username);
        Config::set('mail.mailers.smtp.password', Crypt::decryptString($setting->encrypted_password));
        Config::set('mail.from.address', $setting->from_address);
        Config::set('mail.from.name', $setting->from_name ?: 'virVPN');
        app('mail.manager')->purge('smtp');
    }

    public function sendTest(string $recipient): void
    {
        $this->configure();
        Mail::raw('This is a virVPN SMTP delivery test. If you received it, email verification is ready.', function ($message) use ($recipient) {
            $message->to($recipient)->subject('virVPN email delivery test');
        });
    }
}
