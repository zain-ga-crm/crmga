<?php

use App\Jobs\DeliverWebhookJob;
use App\Models\Lead;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Support\Webhooks\WebhookDispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

// Z-8.3 -- DatabaseTruncation, not RefreshDatabase (see IngestTest.php).
uses(DatabaseTruncation::class);

beforeEach(function () {
    promotePrimaryTenant();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('matches an exact event, a module wildcard, and the global wildcard', function (string $subscribed, string $event, bool $expected) {
    Queue::fake();
    WebhookSubscription::factory()->create(['event' => $subscribed]);

    app(WebhookDispatcher::class)->dispatch($event, ['id' => 'x']);

    if ($expected) {
        Queue::assertPushedOn('integrations', DeliverWebhookJob::class, fn ($job) => $job->event === $event);
    } else {
        Queue::assertNotPushed(DeliverWebhookJob::class);
    }
})->with([
    ['leads.created', 'leads.created', true],
    ['leads.*', 'leads.created', true],
    ['leads.*', 'companies.created', false],
    ['*', 'companies.created', true],
    ['companies.created', 'leads.created', false],
]);

it('does not dispatch to an inactive subscription', function () {
    Queue::fake();
    WebhookSubscription::factory()->create(['event' => '*', 'active' => false]);

    app(WebhookDispatcher::class)->dispatch('leads.created', ['id' => 'x']);

    Queue::assertNotPushed(DeliverWebhookJob::class);
});

it('suppresses dispatch for the duration of the callback', function () {
    Queue::fake();
    WebhookSubscription::factory()->create(['event' => '*']);

    WebhookDispatcher::suppress(function () {
        app(WebhookDispatcher::class)->dispatch('leads.created', ['id' => 'x']);
    });
    Queue::assertNotPushed(DeliverWebhookJob::class);

    app(WebhookDispatcher::class)->dispatch('leads.created', ['id' => 'x']);
    Queue::assertPushed(DeliverWebhookJob::class);
});

it('fires leads.created and leads.updated for a Lead via FiresWebhookEvents', function () {
    Queue::fake();
    WebhookSubscription::factory()->create(['event' => 'leads.*']);

    $lead = Lead::factory()->create();
    Queue::assertPushedOn('integrations', DeliverWebhookJob::class, fn ($job) => $job->event === 'leads.created' && $job->payload['id'] === $lead->id);

    $lead->update(['first_name' => 'Changed']);
    Queue::assertPushedOn('integrations', DeliverWebhookJob::class, fn ($job) => $job->event === 'leads.updated' && $job->payload['id'] === $lead->id);

    $lead->delete();
    Queue::assertPushedOn('integrations', DeliverWebhookJob::class, fn ($job) => $job->event === 'leads.deleted' && $job->payload['id'] === $lead->id);
});

it('signs a delivery with HMAC-SHA256 and records a successful delivery row', function () {
    Http::fake(['example.com/*' => Http::response('ok', 200)]);
    $subscription = WebhookSubscription::factory()->create(['url' => 'https://example.com/hook', 'secret' => 'shh']);

    (new DeliverWebhookJob($subscription->id, 'leads.created', ['id' => 'lead-1']))->handle();

    Http::assertSent(function ($request) {
        $body = (string) $request->body();
        $expected = hash_hmac('sha256', $body, 'shh');

        return $request->url() === 'https://example.com/hook'
            && $request->header('X-Signature')[0] === $expected;
    });

    $delivery = WebhookDelivery::where('subscription_id', $subscription->id)->sole();
    expect($delivery->response_status)->toBe(200)
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($delivery->failed_at)->toBeNull();
});

it('records a failed delivery row and rethrows so the queue retries', function () {
    Http::fake(['example.com/*' => Http::response('nope', 500)]);
    $subscription = WebhookSubscription::factory()->create(['url' => 'https://example.com/hook']);

    expect(fn () => (new DeliverWebhookJob($subscription->id, 'leads.created', ['id' => 'lead-1']))->handle())
        ->toThrow(RuntimeException::class);

    $delivery = WebhookDelivery::where('subscription_id', $subscription->id)->sole();
    expect($delivery->response_status)->toBe(500)
        ->and($delivery->delivered_at)->toBeNull()
        ->and($delivery->failed_at)->not->toBeNull();
});

it('does nothing for a deleted or inactive subscription', function () {
    Http::fake();
    $subscription = WebhookSubscription::factory()->create(['active' => false]);

    (new DeliverWebhookJob($subscription->id, 'leads.created', ['id' => 'lead-1']))->handle();

    Http::assertNothingSent();
    expect(WebhookDelivery::count())->toBe(0);
});

it('retries with the documented backoff schedule', function () {
    expect((new DeliverWebhookJob('sub-id', 'leads.created', ['id' => 'x']))->backoff())
        ->toBe([60, 300, 900, 1800, 3600]);
});
