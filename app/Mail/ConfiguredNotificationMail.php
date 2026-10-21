<?php

namespace App\Mail;

use App\Models\Invitee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConfiguredNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitee $invitee, public string $mailSubject, public string $mailBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.configured-notification', with: ['guest' => $this->invitee, 'event' => $this->invitee->event, 'body' => $this->mailBody]);
    }
}
