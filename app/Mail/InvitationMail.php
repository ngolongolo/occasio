<?php

namespace App\Mail;

use App\Models\Invitee;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitee $invitee)
    {
        $this->invitee->loadMissing('event');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your invitation: '.$this->invitee->event->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
            with: [
                'g' => $this->invitee,
                'e' => $this->invitee->event,
                'logoPath' => $this->invitee->event->logo_path
                    ? Storage::disk('local')->path($this->invitee->event->logo_path)
                    : null,
            ],
        );
    }

    public function attachments(): array
    {
        $event = $this->invitee->event;

        if (! $event->invitation_card_path) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $event->invitation_card_path)
                ->as($event->invitation_card_name ?: 'invitation-card')
                ->withMime($event->invitation_card_mime ?: 'application/octet-stream'),
        ];
    }
}
