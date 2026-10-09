<?php

namespace App\Notifications;

use App\Services\PasswordOtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetOtp extends Notification
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = PasswordOtpService::EXPIRES_IN_MINUTES;

        return (new MailMessage)
            ->subject(__('Your password reset code'))
            ->greeting(__('Password reset'))
            ->line(__('Enter this code to choose a new password:'))
            // The code carries on its own line, spaced, so it survives a mail
            // client that decides a run of digits is a phone number.
            ->line('**'.$this->spaced().'**')
            ->line(__('The code expires in :minutes minutes and can only be used once.', ['minutes' => $minutes]))
            ->line(__('If you did not ask for this, you can ignore this email — your password will not change.'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }

    private function spaced(): string
    {
        return implode(' ', str_split($this->code));
    }
}
