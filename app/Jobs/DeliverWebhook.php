<?php

namespace App\Jobs;

use App\Enums\WebhookStatus;
use App\Models\WebhookDelivery;
use App\Webhooks\WebhookSignature;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Posts one event to one service, signed with the service's secret. Every non-2xx answer or
 * connection error is retried with growing delays (about 10 hours in total); the result of each
 * attempt is kept in the delivery.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public WebhookDelivery $delivery)
    {
        $this->afterCommit();
    }

    /**
     * Seconds before the retries 2 to 8 (exponential, capped at 6 hours).
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900, 3600, 10800, 21600];
    }

    public function handle(): void
    {
        $delivery = $this->delivery;
        $service = $delivery->service;

        if ($delivery->status === WebhookStatus::Delivered) {
            return;
        }

        if (! $service->receivesWebhooks()) {
            $delivery->update(['status' => WebhookStatus::Failed, 'last_error' => 'The service has no webhook URL or secret anymore.']);

            return;
        }

        $delivery->increment('attempts');
        $timestamp = time();

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withUserAgent('AccountManager-Webhooks')
                ->withHeaders([
                    WebhookSignature::EVENT_HEADER => $delivery->event,
                    WebhookSignature::EVENT_ID_HEADER => $delivery->event_id,
                    WebhookSignature::TIMESTAMP_HEADER => (string) $timestamp,
                    WebhookSignature::SIGNATURE_HEADER => WebhookSignature::sign((string) $service->secret, $timestamp, $delivery->payload),
                ])
                ->withBody($delivery->payload, 'application/json')
                ->post((string) $service->webhook_url);
        } catch (ConnectionException $exception) {
            $delivery->update(['response_status' => null, 'last_error' => Str::limit($exception->getMessage(), 1000)]);

            throw $exception;
        }

        if ($response->successful()) {
            $delivery->update([
                'status' => WebhookStatus::Delivered,
                'response_status' => $response->status(),
                'last_error' => null,
                'delivered_at' => now(),
            ]);

            return;
        }

        $error = trim("HTTP {$response->status()} ".Str::limit($response->body(), 500));
        $delivery->update(['response_status' => $response->status(), 'last_error' => $error]);

        throw new RuntimeException("Webhook {$delivery->event_id} to service {$service->key} failed: {$error}");
    }

    public function failed(?Throwable $exception): void
    {
        $this->delivery->update(['status' => WebhookStatus::Failed]);
    }
}
