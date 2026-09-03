<?php

namespace App\Support\Webhooks;

use App\Jobs\DeliverWebhookJob;
use App\Models\WebhookSubscription;
use Illuminate\Support\Str;

/**
 * Outbound webhooks (STUDIO_API_RBAC.md §2.2). Fired generically from
 * HasAcl::bootHasAcl() for every model's create/update/delete -- the same
 * "one engine, every module including Studio-created ones" principle the
 * rest of this app follows, rather than wiring each entity by hand.
 *
 * A subscription's `event` matches an incoming event three ways: exact
 * ("leads.created" == "leads.created"), module wildcard ("leads.*" matches
 * any "leads.*" event), or everything ("*"). One matching subscription
 * queues one DeliverWebhookJob.
 */
final class WebhookDispatcher
{
    private static bool $suppressed = false;

    /**
     * Suppress dispatch for the duration of $callback -- for bulk operations
     * (crm:migrate-legacy) where thousands of historical rows would otherwise
     * each fire a "just created" notification to external systems.
     */
    public static function suppress(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(string $event, array $payload): void
    {
        if (self::$suppressed) {
            return;
        }

        WebhookSubscription::query()
            ->where('active', true)
            ->get()
            ->filter(fn (WebhookSubscription $subscription): bool => self::matches($subscription->event, $event))
            ->each(fn (WebhookSubscription $subscription) => DeliverWebhookJob::dispatch($subscription->id, $event, $payload));
    }

    private static function matches(string $subscribed, string $event): bool
    {
        if ($subscribed === '*' || $subscribed === $event) {
            return true;
        }

        return $subscribed === Str::before($event, '.').'.*';
    }
}
