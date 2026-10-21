<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class OkesheniResetPassword extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        $broker = config('auth.defaults.passwords');
        $expires = (int) config("auth.passwords.{$broker}.expire", 60);

        return (new MailMessage)
            ->subject('Reset your Okesheni password')
            ->view('emails.password-reset', [
                'user' => $notifiable,
                'resetUrl' => $url,
                'expires' => $expires,
            ]);
    }
}
