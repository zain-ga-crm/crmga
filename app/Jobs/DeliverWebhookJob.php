<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * §11's own queue table: "integrations: ... outbound calls to providers |
 * 5 tries, exponential to 1 hour." Signs the payload per STUDIO_API_RBAC.md
 * §2.2 (HMAC-SHA256 over the raw JSON body, timestamp alongside it so the
 * receiver can also reject stale/replayed deliveries).
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $subscriptionId,
        public readonly string $event,
        public readonly array $payload,
    ) {
        $this->onQueue('integrations');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(): void
    {
        $subscription = WebhookSubscription::query()->find($this->subscriptionId);
        if ($subscription === null || ! $subscription->active) {
            return;
        }

        $timestamp = now()->timestamp;
        $body = json_encode(['event' => $this->event, 'data' => $this->payload, 'timestamp' => $timestamp]);
        $body = $body === false ? '{}' : $body;
        $signature = hash_hmac('sha256', $body, $subscription->secret);

        $delivery = WebhookDelivery::create([
            'subscription_id' => $subscription->id,
            'event' => $this->event,
            'payload' => $this->payload,
            'attempt' => $this->attempts(),
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Signature' => $signature,
                'X-Timestamp' => (string) $timestamp,
            ])->withBody($body, 'application/json')->post($subscription->url);
        } catch (\Throwable $e) {
            $delivery->update(['failed_at' => now(), 'response_body' => $e->getMessage()]);
            throw $e;
        }

        $delivery->update([
            'response_status' => $response->status(),
            'response_body' => mb_substr($response->body(), 0, 2000),
            'delivered_at' => $response->successful() ? now() : null,
            'failed_at' => $response->successful() ? null : now(),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Webhook delivery to [{$subscription->url}] failed with status {$response->status()}.");
        }
    }
}
