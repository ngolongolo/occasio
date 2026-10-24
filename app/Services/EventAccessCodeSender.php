<?php

namespace App\Services;

use App\Mail\EventAccessCodeMail;
use App\Models\EventAccessChallenge;
use Illuminate\Support\Facades\{Http, Mail};

class EventAccessCodeSender
{
    public function send(EventAccessChallenge $challenge, string $code): void
    {
        if ($challenge->channel === 'email') {
            Mail::to($challenge->destination)->send(new EventAccessCodeMail($challenge->event, $code));
            $challenge->update(['delivery_status' => 'sent']);
            return;
        }
        if (config('services.delivery_mode') !== 'live') {
            $challenge->update(['delivery_status' => 'previewed']);
            return;
        }
        $config = config('services.sprint_sms');
        foreach (['url', 'api_id', 'api_password', 'sender_id'] as $key) {
            if (empty($config[$key])) throw new \RuntimeException('SMS verification is not configured.');
        }
        Http::timeout(25)->get($config['url'], [
            'api_id' => $config['api_id'], 'api_password' => $config['api_password'], 'sms_type' => 'P', 'encoding' => 'T',
            'sender_id' => $config['sender_id'], 'phonenumber' => $challenge->destination,
            'textmessage' => "Your Okesheni verification code is {$code}. It expires in 10 minutes.",
        ])->throw();
        $challenge->update(['delivery_status' => 'sent']);
    }
}
