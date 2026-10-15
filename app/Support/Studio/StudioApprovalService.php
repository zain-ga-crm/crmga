<?php

namespace App\Support\Studio;

use App\Models\Metadata\Change;
use App\Support\LayoutManager;
use App\Support\OptionListManager;
use App\Support\SchemaManager\FieldChangeRequest;
use App\Support\SchemaManager\SchemaManager;
use RuntimeException;
use Throwable;

/**
 * S-8.3: replays a 'requested' Change row the real way -- through the exact
 * same SchemaManager/OptionListManager/LayoutManager call a self-service
 * admin would have made, so an approved request is indistinguishable from
 * one that was never gated. Must be called with the target tenant already
 * the active connection (tenancy()->initialize()) -- see StudioApprovals,
 * the only caller, which is also the only place a platform operator (not a
 * tenant User) acts on a tenant's own `changes` table.
 */
final class StudioApprovalService
{
    public function __construct(
        private readonly SchemaManager $schemaManager,
        private readonly OptionListManager $optionListManager,
        private readonly LayoutManager $layoutManager,
    ) {}

    public function approve(Change $change, ?string $reviewerId): void
    {
        $this->guardRequested($change);

        $payload = is_array($change->payload) ? $change->payload : [];
        // The requester, not the approver -- the applied Change's own actor_id
        // (and an option/layout change's audit trail) should still read as
        // whoever asked for it, same as it would have under self_service.
        $requesterId = $change->actor_id;

        try {
            if (str_starts_with($change->kind, 'field.')) {
                $this->replayField($payload, $requesterId);
            } elseif ($change->kind === 'option.requested') {
                $this->replayOptionList($payload, $requesterId);
            } elseif ($change->kind === 'layout.requested') {
                $this->replayLayout($payload, $requesterId);
            } else {
                throw new RuntimeException("Unknown request kind [{$change->kind}].");
            }

            $change->update(['status' => 'approved', 'reviewer_id' => $reviewerId]);
        } catch (Throwable $e) {
            $change->update([
                'status' => 'rejected',
                'reviewer_id' => $reviewerId,
                'review_note' => 'Approval failed: '.$e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function reject(Change $change, ?string $reviewerId, ?string $note = null): void
    {
        $this->guardRequested($change);

        $change->update(['status' => 'rejected', 'reviewer_id' => $reviewerId, 'review_note' => $note]);
    }

    private function guardRequested(Change $change): void
    {
        if ($change->status !== 'requested') {
            throw new RuntimeException('Only a requested change can be approved or rejected.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayField(array $payload, ?string $actorId): void
    {
        $rawAction = $this->str($payload, 'action') ?? '';
        $action = match ($rawAction) {
            'add' => 'add',
            'modify' => 'modify',
            'delete' => 'delete',
            default => throw new RuntimeException("Unknown field request action [{$rawAction}]."),
        };

        $request = new FieldChangeRequest(
            action: $action,
            moduleKey: $this->str($payload, 'module_key') ?? '',
            name: $this->str($payload, 'name') ?? '',
            type: $this->str($payload, 'type'),
            options: $this->arr($payload, 'options'),
            confirmLossy: $this->bool($payload, 'confirm_lossy'),
        );

        $plan = $this->schemaManager->plan($request);
        $result = $this->schemaManager->apply($plan, actorId: $actorId);

        if (! $result->success) {
            throw new RuntimeException($result->error ?? 'Schema change failed.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayOptionList(array $payload, ?string $actorId): void
    {
        $method = $this->str($payload, 'method') ?? '';
        $args = $this->arr($payload, 'args');

        match ($method) {
            'createList' => $this->optionListManager->createList(
                $this->str($args, 'key') ?? '',
                $this->str($args, 'label') ?? '',
                actorId: $actorId,
            ),
            'renameList' => $this->optionListManager->renameList(
                $this->str($args, 'list_key') ?? '',
                $this->str($args, 'new_label') ?? '',
                actorId: $actorId,
            ),
            'addItem' => $this->optionListManager->addItem(
                $this->str($args, 'list_key') ?? '',
                $this->str($args, 'value') ?? '',
                $this->str($args, 'label') ?? '',
                color: $this->str($args, 'color'),
                isActive: $this->bool($args, 'is_active', true),
                actorId: $actorId,
            ),
            'updateItem' => $this->optionListManager->updateItem(
                $this->str($args, 'list_key') ?? '',
                $this->str($args, 'value') ?? '',
                newValue: $this->str($args, 'new_value'),
                newLabel: $this->str($args, 'new_label'),
                newColor: $this->str($args, 'new_color'),
                newIsActive: array_key_exists('new_is_active', $args) ? $this->bool($args, 'new_is_active') : null,
                confirmLossy: $this->bool($args, 'confirm_lossy'),
                actorId: $actorId,
            ),
            'removeItem' => $this->optionListManager->removeItem(
                $this->str($args, 'list_key') ?? '',
                $this->str($args, 'value') ?? '',
                confirmLossy: $this->bool($args, 'confirm_lossy'),
                actorId: $actorId,
            ),
            'reorderItems' => $this->optionListManager->reorderItems(
                $this->str($args, 'list_key') ?? '',
                $this->strList($args, 'ordered_values'),
                actorId: $actorId,
            ),
            default => throw new RuntimeException("Unknown option-list request method [{$method}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayLayout(array $payload, ?string $actorId): void
    {
        $layoutId = $this->str($payload, 'layout_id');
        if ($layoutId === null) {
            throw new RuntimeException('No layout_id on this request.');
        }

        $this->layoutManager->publish($layoutId, actorId: $actorId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function str(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bool(array $payload, string $key, bool $default = false): bool
    {
        $value = $payload[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function arr(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function strList(array $payload, string $key): array
    {
        $value = $payload[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
