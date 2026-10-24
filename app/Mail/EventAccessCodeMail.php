<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};
use Illuminate\Queue\SerializesModels;

class EventAccessCodeMail extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public Event $event, public string $code) {}
    public function envelope(): Envelope { return new Envelope(subject: 'Your Okesheni verification code'); }
    public function content(): Content { return new Content(view: 'emails.event-access-code'); }
}
