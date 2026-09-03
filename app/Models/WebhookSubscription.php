<?php

namespace App\Models;

use Database\Factories\WebhookSubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant-configured outbound webhook (STUDIO_API_RBAC.md §2.2). `event`
 * matches an exact name ("leads.created"), a module wildcard ("leads.*"), or
 * every event ("*") -- see WebhookDispatcher::matches().
 *
 * @property string $id
 * @property string $event
 * @property string $url
 * @property string $secret
 * @property bool $active
 * @property string|null $created_by
 */
class WebhookSubscription extends Model
{
    /** @use HasFactory<WebhookSubscriptionFactory> */
    use HasFactory;

    use HasVersion7Uuids;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['event', 'url', 'secret', 'active', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'secret' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }
}
