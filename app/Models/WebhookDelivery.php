<?php

namespace App\Models;

use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per delivery attempt (see the migration for why). Immutable once
 * written -- a retry creates a new row via a new DeliverWebhookJob attempt,
 * it never updates a previous one.
 *
 * @property string $id
 * @property string $subscription_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property int $attempt
 * @property int|null $response_status
 * @property string|null $response_body
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 */
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    use HasVersion7Uuids;

    /** @var list<string> */
    protected $fillable = [
        'subscription_id', 'event', 'payload', 'attempt',
        'response_status', 'response_body', 'delivered_at', 'failed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }

    public function successful(): bool
    {
        return $this->delivered_at !== null;
    }
}
