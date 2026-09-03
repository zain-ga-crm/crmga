<?php

namespace App\Models\Concerns;

use App\Support\Acl\Aclable;
use App\Support\Webhooks\WebhookDispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Fires "{module}.created"/"{module}.updated"/"{module}.deleted" for outbound
 * webhook subscriptions (STUDIO_API_RBAC.md §2.2) -- kept separate from
 * HasAcl (that trait is about access control, not notifications) even though
 * every model using this also uses HasAcl today. The payload is
 * deliberately lean (id + a timestamp, not the full record): a subscriber
 * already has API credentials and can fetch the record itself, and this
 * avoids ever putting a module's PII directly on the wire to a third-party
 * URL an administrator configured, which the record-level API already
 * ACL-gates and this endpoint does not.
 *
 * @mixin Model
 */
trait FiresWebhookEvents
{
    protected static function bootFiresWebhookEvents(): void
    {
        static::created(fn (Model $model) => self::fireWebhookEvent($model, 'created'));
        static::updated(fn (Model $model) => self::fireWebhookEvent($model, 'updated'));
        static::deleted(fn (Model $model) => self::fireWebhookEvent($model, 'deleted'));
    }

    private static function fireWebhookEvent(Model $model, string $action): void
    {
        if (! $model instanceof Aclable) {
            return;
        }

        app(WebhookDispatcher::class)->dispatch("{$model->moduleKey()}.{$action}", [
            'id' => $model->getKey(),
            'occurred_at' => now()->toIso8601String(),
        ]);
    }
}
