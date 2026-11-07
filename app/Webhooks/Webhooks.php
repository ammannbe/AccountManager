<?php

namespace App\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Service;
use App\Models\WebhookDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Queues one event for the apps: the same signed body (one event_id) for every given service with
 * a webhook URL and a secret, logged in webhook_deliveries and posted by DeliverWebhook.
 */
class Webhooks
{
    /**
     * @param  array<string, mixed>  $data
     * @param  iterable<Service>  $services
     */
    public function send(string $type, array $data, iterable $services): void
    {
        $services = Collection::make($services)->filter(fn (Service $service): bool => $service->receivesWebhooks())->values();

        if ($services->isEmpty()) {
            return;
        }

        $eventId = (string) Str::uuid();
        $payload = json_encode([
            'event_id' => $eventId,
            'type' => $type,
            'occurred_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        foreach ($services as $service) {
            $delivery = WebhookDelivery::query()->create([
                'service_id' => $service->id,
                'event_id' => $eventId,
                'event' => $type,
                'payload' => $payload,
            ]);

            DeliverWebhook::dispatch($delivery);
        }
    }
}
