<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;

final class MfaRecoveryCodeActivityNotification extends Notification
{
    public function __construct(private readonly string $activity) {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    /** @param User $notifiable */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('TLMS security alert: MFA recovery-code activity')
            ->greeting('Hello '.$notifiable->name)
            ->line($this->activity)
            ->line('TLMS never includes recovery codes in security notification emails.')
            ->line('If you did not perform this action, reset your password and revoke active sessions immediately.')
            ->salutation('TLMS Security');
    }
}
