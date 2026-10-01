<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Invitee;

class InvitationTemplate
{
    public const DEFAULT_SMS = 'Hello {guest_name}, {host} invites you to {event_title} on {event_date} at {venue}. RSVP: {rsvp_url}';

    public static function sms(Invitee $guest, Event $event): string
    {
        $template = $event->sms_template ?: self::DEFAULT_SMS;
        $message = strtr($template, [
            '{guest_name}' => $guest->name,
            '{event_title}' => $event->title,
            '{host}' => $event->host,
            '{event_date}' => $event->starts_at->format('d M Y H:i'),
            '{venue}' => $event->venue,
            '{rsvp_url}' => $guest->rsvpUrl(),
        ]);

        return str_contains($template, '{rsvp_url}') ? $message : rtrim($message)." RSVP: {$guest->rsvpUrl()}";
    }
}
