<?php

namespace App\Jobs;

use App\Mail\ConfiguredNotificationMail;
use App\Models\NotificationDelivery;
use App\Services\PostEventMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\{ConnectionException, RequestException};
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendConfiguredNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public int $deliveryId) {}

    public function handle(): void
    {
        $delivery = NotificationDelivery::with('invitee.event', 'invitee.pledge.payments', 'pledgePayment')->findOrFail($this->deliveryId);
        if ($delivery->status !== 'queued') return;
        $delivery->update(['status' => 'processing']);
        $guest = $delivery->invitee;
        $content = PostEventMessage::content($guest, $delivery->type, $delivery->channel, $delivery->pledgePayment);
        if (config('services.delivery_mode') !== 'live') {
            $delivery->update(['status' => 'previewed']);
            return;
        }
        try {
            if ($delivery->channel === 'email') {
                if (config('mail.default') !== 'smtp') throw new \RuntimeException('Configure SMTP before live email sending.');
                Mail::to($guest->email, $guest->name)->send(new ConfiguredNotificationMail($guest, $content['subject'], $content['body']));
                $providerId = null;
            } else {
                $config = config('services.sprint_sms');
                foreach (['url', 'api_id', 'api_password', 'sender_id'] as $key) if (empty($config[$key])) throw new \RuntimeException('Sprint SMS credentials and sender are not configured.');
                $response = Http::timeout(25)->get($config['url'], ['api_id' => $config['api_id'], 'api_password' => $config['api_password'], 'sms_type' => 'P', 'encoding' => 'T', 'sender_id' => $config['sender_id'], 'phonenumber' => $guest->phone, 'textmessage' => $content['body']])->throw();
                $providerId = $response->json('message_id') ?? $response->json('id') ?? $response->json('request_id');
            }
            $delivery->update(['status' => 'submitted', 'provider_id' => $providerId, 'error' => null]);
        } catch (RequestException $exception) {
            $status = $exception->response->status();
            $temporary = $status === 429 || $status >= 500;
            $delivery->update(['status' => 'failed', 'error' => $temporary ? "Messaging provider temporarily unavailable (HTTP {$status}). Retry this notification later." : "Messaging provider rejected the request (HTTP {$status}). Check the channel configuration before retrying."]);
            Log::warning('Configured notification provider request failed.', ['notification_delivery_id' => $delivery->id, 'channel' => $delivery->channel, 'http_status' => $status, 'temporary' => $temporary]);
        } catch (ConnectionException $exception) {
            $delivery->update(['status' => 'failed', 'error' => 'Could not connect to the messaging provider. Retry this notification later.']);
            Log::warning('Configured notification provider connection failed.', ['notification_delivery_id' => $delivery->id, 'channel' => $delivery->channel]);
        } catch (\Throwable $exception) {
            $delivery->update(['status' => 'failed', 'error' => 'Notification submission failed. Check provider configuration and logs before retrying.']);
            report($exception);
        }
    }
}
