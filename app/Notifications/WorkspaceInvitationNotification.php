<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly WorkspaceInvitation $invitation,
        #[\SensitiveParameter] private readonly string $plainToken,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/accept-invitation?'.http_build_query([
            'token' => $this->plainToken,
        ]);

        return (new MailMessage)
            ->subject('You have been invited to '.$this->invitation->workspace->name)
            ->line($this->invitation->inviter->name.' invited you to join '.$this->invitation->workspace->name.' on '.config('app.name').'.')
            ->action('Accept invitation', $url)
            ->line('This invitation expires on '.$this->invitation->expires_at->toFormattedDateString().'.');
    }
}
