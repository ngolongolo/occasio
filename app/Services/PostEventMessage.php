<?php

namespace App\Services;

use App\Models\Invitee;
use App\Models\PledgePayment;

class PostEventMessage
{
    public static function content(Invitee $guest, string $type, string $channel, ?PledgePayment $payment = null): array
    {
        $guest->loadMissing(['event', 'pledge']);
        $event = $guest->event;
        $attendance = $type === 'attendance_thanks';
        $receipt = $type === 'pledge_payment_receipt';
        $subject = $attendance ? ($event->attendance_email_subject ?: 'Thank you for attending {event_title}')
            : ($receipt ? ($event->payment_receipt_email_subject ?: 'Pledge payment receipt for {event_title}') : ($event->pledge_email_subject ?: 'Thank you for your pledge to {event_title}'));
        $body = $attendance ? ($channel === 'sms'
                ? ($event->attendance_sms_message ?: 'Thank you {guest_name} for attending {event_title}. We appreciated your presence.')
                : ($event->attendance_email_message ?: "Dear {guest_name},\n\nThank you for attending {event_title}. We truly appreciated your presence.\n\nWith gratitude,\n{host}"))
            : ($receipt ? ($channel === 'sms'
                ? ($event->payment_receipt_sms_message ?: 'Received {currency} {payment_amount} for {event_title}. Balance: {currency} {pledge_balance}. Ref: {payment_reference}.')
                : ($event->payment_receipt_email_message ?: "Dear {guest_name},\n\nWe received {currency} {payment_amount} toward your pledge for {event_title}. Your remaining balance is {currency} {pledge_balance}.\n\nReference: {payment_reference}"))
            : ($channel === 'sms'
                ? ($event->pledge_sms_message ?: 'Thank you {guest_name}. Your payment of {currency} {pledge_amount} for {event_title} has been confirmed.')
                : ($event->pledge_email_message ?: "Dear {guest_name},\n\nThank you for completing your pledge payment of {currency} {pledge_amount} to {event_title}. Your support is deeply appreciated.\n\nWith gratitude,\n{host}")));

        $values = [
            '{guest_name}' => $guest->name,
            '{event_title}' => $event->title,
            '{host}' => $event->host,
            '{event_date}' => $event->starts_at->format('d M Y'),
            '{venue}' => $event->venue,
            '{attended_count}' => (string) $guest->attended_count,
            '{currency}' => $event->pledge_currency,
            '{pledge_amount}' => $guest->pledge ? number_format((float) $guest->pledge->amount, 2) : '0.00',
            '{paid_amount}' => $guest->pledge ? number_format((float) $guest->pledge->paid_amount, 2) : '0.00',
            '{pledge_balance}' => $guest->pledge ? number_format((float) max(0, (float) $guest->pledge->balance), 2) : '0.00',
            '{payment_amount}' => $payment ? number_format((float) $payment->amount, 2) : '0.00',
            '{payment_method}' => $payment?->payment_method ?: 'N/A',
            '{payment_reference}' => $payment?->reference ?: 'N/A',
            '{payment_date}' => $payment?->payment_date?->format('d M Y') ?: 'N/A',
        ];

        return ['subject' => strtr($subject, $values), 'body' => strtr($body, $values)];
    }
}
