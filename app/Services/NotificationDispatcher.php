<?php

namespace App\Services;

use App\Jobs\SendConfiguredNotification;
use App\Models\{Invitee, PledgePayment};

class NotificationDispatcher
{
    public function dispatch(Invitee $guest, string $type, array $channels, bool $force = false, ?PledgePayment $payment = null): int
    {
        $count = 0;
        foreach (array_unique($channels) as $channel) {
            if (($channel === 'email' && ! $guest->email) || ($channel === 'sms' && ! $guest->phone)) continue;
            $key = $payment ? 'payment:'.$payment->id : 'default';
            $delivery = $guest->notificationDeliveries()->firstOrNew(['type' => $type, 'channel' => $channel, 'deduplication_key' => $key]);
            if ($delivery->exists && in_array($delivery->status, ['queued', 'processing', 'submitted'], true) && ! $force) continue;
            $delivery->fill(['status' => 'queued', 'provider_id' => null, 'error' => null, 'pledge_payment_id' => $payment?->id])->save();
            SendConfiguredNotification::dispatch($delivery->id);
            $count++;
        }
        return $count;
    }
}
