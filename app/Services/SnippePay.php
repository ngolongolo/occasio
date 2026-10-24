<?php

namespace App\Services;

use App\Models\EventSettlement;
use App\Models\ParticipationPayment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class SnippePay
{
    public function normalizeMsisdn(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        if (str_starts_with($digits, '0')) $digits = '255'.substr($digits, 1);
        if (str_starts_with($digits, '255') && strlen($digits) === 12) return $digits;
        throw ValidationException::withMessages(['msisdn' => 'Enter a valid Tanzanian mobile number, for example 0656414320.']);
    }

    public function networkFor(string $msisdn): int
    {
        $prefix = substr($msisdn, 3, 2);
        return match (true) {
            in_array($prefix, ['65', '67', '71', '77'], true) => 1,
            in_array($prefix, ['68', '69', '78'], true) => 2,
            in_array($prefix, ['74', '75', '76'], true) => 3,
            $prefix === '62' => 4,
            default => throw ValidationException::withMessages(['msisdn' => 'This mobile network is not currently supported.']),
        };
    }

    public function requestPayment(ParticipationPayment $payment): array
    {
        $guest = $payment->invitee()->with('event')->firstOrFail();
        $names = preg_split('/\s+/', trim($guest->name), 2);
        $payload = [
            'payment_type' => 'mobile',
            'details' => ['amount' => (int) round((float) $payment->amount), 'currency' => $payment->currency],
            'phone_number' => $payment->msisdn,
            'customer' => [
                'firstname' => $names[0] ?: 'Guest',
                'lastname' => $names[1] ?? 'Guest',
                'email' => $guest->email ?: 'guest-'.$guest->id.'@payments.okesheni.co.tz',
            ],
            'metadata' => ['okesheni_payment' => $payment->reference, 'invitee_id' => $guest->id],
        ];

        $webhookUrl = $this->webhookUrl('api.snippe.webhook');
        if ($webhookUrl !== null) {
            $payload['webhook_url'] = $webhookUrl;
        }

        $recordFailure = function (array $response, string $message) use ($payment): void {
            $payment->update([
                'request_response' => $response,
                'provider_remarks' => $message,
                'requested_at' => now(),
                'status' => 'request_failed',
                'failed_at' => now(),
            ]);
        };
        $body = $this->post('/v1/payments', $payload, $payment->reference, 'msisdn', $recordFailure);
        $providerReference = data_get($body, 'data.reference');
        if (! is_string($providerReference) || $providerReference === '') {
            $recordFailure($body, 'Snippe returned an incomplete payment response.');
            throw ValidationException::withMessages(['msisdn' => 'Snippe returned an incomplete payment response. Please try again.']);
        }
        $payment->refresh();
        if ($payment->provider_payment_reference !== null && ! hash_equals($payment->provider_payment_reference, $providerReference)) {
            throw ValidationException::withMessages(['msisdn' => 'Snippe returned a payment reference that does not match its webhook.']);
        }
        $providerCompleted = data_get($body, 'data.status') === 'completed';
        $terminalStatus = in_array($payment->status, ['paid', 'failed'], true) ? $payment->status : null;
        $status = $terminalStatus ?? ($providerCompleted ? 'paid' : 'pending');
        $payment->update([
            'provider_payment_reference' => $providerReference,
            'request_response' => $body,
            'provider_remarks' => $terminalStatus ? $payment->provider_remarks : null,
            'requested_at' => now(),
            'status' => $status,
            'paid_at' => $status === 'paid' ? ($payment->paid_at ?? now()) : null,
            'failed_at' => $status === 'failed' ? ($payment->failed_at ?? now()) : null,
        ]);
        return $body;
    }

    public function paymentStatus(ParticipationPayment $payment): array
    {
        $reference = (string) $payment->provider_payment_reference;
        if ($reference === '') {
            throw ValidationException::withMessages(['payment' => 'This payment request has no Snippe reference. Send a new payment prompt.']);
        }

        return $this->get('/v1/payments/'.rawurlencode($reference), 'payment');
    }

    public function requestPayout(EventSettlement $settlement): array
    {
        $event = $settlement->event;
        $isBank = strcasecmp($settlement->payment_type, 'Ecobank') === 0;
        $payload = [
            'amount' => (int) round((float) $settlement->amount),
            'channel' => $isBank ? 'bank' : 'mobile',
            'recipient_name' => $settlement->account_name ?: $event->host,
            'narration' => 'Okesheni event settlement '.$event->public_code,
            'metadata' => ['okesheni_settlement' => $settlement->reference, 'event_id' => $event->id],
        ];
        $webhookUrl = $this->webhookUrl('api.snippe.webhook');
        if ($webhookUrl !== null) {
            $payload['webhook_url'] = $webhookUrl;
        }
        if ($isBank) {
            $payload['recipient_bank'] = 'ECOBANK';
            $payload['recipient_account'] = preg_replace('/\s+/', '', $settlement->payment_number);
        } else {
            $payload['recipient_phone'] = $this->normalizeMsisdn($settlement->payment_number);
        }

        $body = $this->post('/v1/payouts/send', $payload, $settlement->reference, 'amount');
        if (! is_string(data_get($body, 'data.reference')) || data_get($body, 'data.reference') === '') {
            throw ValidationException::withMessages(['amount' => 'Snippe returned an incomplete disbursement response. Please try again.']);
        }
        return $body;
    }

    public function verifyWebhook(Request $request): array
    {
        $secret = (string) config('services.snippe.webhook_secret');
        if ($secret === '') {
            abort_if(app()->environment('production'), 503, 'Snippe webhook verification is not configured.');
            return $request->json()->all();
        }

        $timestamp = (string) $request->header('X-Webhook-Timestamp');
        $signature = (string) $request->header('X-Webhook-Signature');
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 401, 'Stale Snippe webhook.');
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless(strlen($signature) === 64 && hash_equals($expected, $signature), 401, 'Invalid Snippe webhook signature.');
        return $request->json()->all();
    }

    private function post(string $path, array $payload, string $idempotencyKey, string $errorKey, ?callable $onFailure = null): array
    {
        $key = (string) config('services.snippe.key');
        if ($key === '') {
            $message = 'Snippe is not configured. Add SNIPPE_KEY to the server environment.';
            if ($onFailure !== null) $onFailure(['error' => 'missing_configuration'], $message);
            throw ValidationException::withMessages([$errorKey => $message]);
        }
        try {
            $response = Http::withToken($key)->acceptJson()->asJson()
                ->withHeaders(['Idempotency-Key' => substr($idempotencyKey, 0, 30)])
                ->timeout((int) config('services.snippe.timeout', 30))
                ->post(rtrim((string) config('services.snippe.base_url'), '/').$path, $payload);
        } catch (ConnectionException) {
            $message = 'Snippe could not be reached. Please try again.';
            if ($onFailure !== null) $onFailure(['error' => 'connection_failed'], $message);
            throw ValidationException::withMessages([$errorKey => $message]);
        }
        $body = $response->json() ?? ['raw' => mb_substr(strip_tags($response->body()), 0, 4000)];
        if (! $response->successful() || data_get($body, 'status') !== 'success') {
            $message = $this->providerMessage($body, 'Snippe rejected the request. Please try again.');
            if ($onFailure !== null) $onFailure(['http_status' => $response->status(), 'body' => $body], $message);
            throw ValidationException::withMessages([$errorKey => $message]);
        }
        return $body;
    }

    private function get(string $path, string $errorKey): array
    {
        $key = (string) config('services.snippe.key');
        if ($key === '') {
            throw ValidationException::withMessages([$errorKey => 'Snippe is not configured. Add SNIPPE_KEY to the server environment.']);
        }

        try {
            $response = Http::withToken($key)->acceptJson()
                ->timeout((int) config('services.snippe.timeout', 30))
                ->get(rtrim((string) config('services.snippe.base_url'), '/').$path);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([$errorKey => 'Snippe could not be reached. Please try again.']);
        }

        $body = $response->json() ?? ['raw' => mb_substr(strip_tags($response->body()), 0, 4000)];
        if (! $response->successful() || data_get($body, 'status') !== 'success') {
            throw ValidationException::withMessages([$errorKey => $this->providerMessage($body, 'Snippe could not retrieve this payment. Please try again.')]);
        }

        return $body;
    }

    private function providerMessage(array $body, string $fallback): string
    {
        $messages = [];
        foreach ([data_get($body, 'message'), data_get($body, 'error.message')] as $message) {
            if (is_string($message) && trim($message) !== '') $messages[] = trim($message);
        }
        $errors = data_get($body, 'errors');
        if (is_array($errors)) {
            array_walk_recursive($errors, function ($message) use (&$messages): void {
                if (is_string($message) && trim($message) !== '') $messages[] = trim($message);
            });
        }
        $message = implode(' ', array_unique($messages));

        return $message !== '' ? mb_substr($message, 0, 250) : $fallback;
    }

    private function isSecureUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function webhookUrl(string $routeName, array|string $parameters = []): ?string
    {
        $url = route($routeName, $parameters);
        $configuredUrl = rtrim((string) config('services.snippe.webhook_base_url'), '/');
        if ($configuredUrl !== '') {
            $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $query = (string) parse_url($url, PHP_URL_QUERY);
            $configuredPath = '/'.ltrim((string) parse_url($configuredUrl, PHP_URL_PATH), '/');
            $url = rtrim($configuredPath, '/') === rtrim($path, '/')
                ? $configuredUrl
                : $configuredUrl.$path;
            if ($query !== '') $url .= '?'.$query;
        }

        return $this->isSecureUrl($url) ? $url : null;
    }
}
