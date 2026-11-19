<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailOtpNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = max(1, (int) config('auth.email_otp_ttl_minutes', 10));

        return (new MailMessage)
            ->subject('Your Inboxly verification code')
            ->line('Use this code to verify your email and connect Shopify:')
            ->line('**'.$this->code.'**')
            ->line("This code expires in {$minutes} minutes.")
            ->line('If you did not create an Inboxly account, you can ignore this email.');
    }
}
