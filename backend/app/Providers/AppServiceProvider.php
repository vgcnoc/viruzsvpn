<?php

namespace App\Providers;

use App\Models\VpnServer;
use App\Policies\VpnServerPolicy;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(VpnServer::class, VpnServerPolicy::class);

        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new MailMessage)
            ->subject('Verify your virVPN email address')
            ->greeting('Welcome to virVPN')
            ->line('Please verify your email address to activate your virVPN account.')
            ->action('Verify email address', $url)
            ->line('If you did not create this account, no further action is required.'));
    }
}
