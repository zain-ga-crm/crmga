<?php

namespace App\Filament\Platform\Pages;

use App\Filament\Platform\Resources\TenantResource;
use App\Models\Metadata\Change;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Support\Studio\StudioApprovalService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Throwable;

/**
 * S-8.3: the pending change-request queue a tenant's Studio pages file into
 * when that tenant's mode is 'request_only' (StudioGovernance::shouldQueue(),
 * checked at every FieldManager/DropdownEditor/LayoutEditor submit site).
 *
 * A Change row lives in the tenant's own database, not the central one a
 * platform operator's session runs on -- so, like TenantStats, every read and
 * write here brackets itself in tenancy()->initialize()/->end() for exactly
 * one tenant at a time, picked from the selector above the list.
 */
class StudioApprovals extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Studio Requests';

    protected static ?string $slug = 'studio-requests';

    protected static string $view = 'filament.platform.pages.studio-approvals';

    public ?string $tenantId = null;

    public function mount(): void
    {
        $tenant = Tenant::query()->orderBy('created_at')->first();
        $this->tenantId = $tenant === null ? null : self::keyOf($tenant);
    }

    public function getTitle(): string
    {
        return 'Studio Requests';
    }

    /**
     * @return array<string, string>
     */
    public function tenantOptions(): array
    {
        $options = [];
        foreach (Tenant::query()->orderBy('created_at')->get() as $tenant) {
            if (! $tenant instanceof Tenant) {
                continue;
            }

            $options[self::keyOf($tenant)] = TenantResource::domainOf($tenant);
        }

        return $options;
    }

    private static function keyOf(Tenant $tenant): string
    {
        $id = $tenant->getKey();

        return is_string($id) ? $id : (is_numeric($id) ? (string) $id : '');
    }

    private function currentTenant(): ?Tenant
    {
        if ($this->tenantId === null) {
            return null;
        }

        $tenant = Tenant::query()->find($this->tenantId);

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * @return list<array{id: string, kind: string, target_module: string|null, target_field: string|null, summary: array<string, mixed>, created_at: string|null}>
     */
    public function pendingRequests(): array
    {
        $tenant = $this->currentTenant();
        if ($tenant === null) {
            return [];
        }

        tenancy()->initialize($tenant);

        try {
            $items = Change::query()
                ->where('status', 'requested')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (Change $change): array => [
                    'id' => $change->id,
                    'kind' => $change->kind,
                    'target_module' => $change->target_module,
                    'target_field' => $change->target_field,
                    'summary' => $this->summarize($change),
                    'created_at' => $change->created_at?->diffForHumans(),
                ])
                ->all();

            return array_values($items);
        } finally {
            tenancy()->end();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Change $change): array
    {
        $payload = is_array($change->payload) ? $change->payload : [];

        return match (true) {
            str_starts_with($change->kind, 'field.') => [
                'Action' => $payload['action'] ?? '—',
                'Field' => $payload['name'] ?? '—',
                'Type' => $payload['type'] ?? '—',
            ],
            $change->kind === 'option.requested' => [
                'Method' => $payload['method'] ?? '—',
                'List' => is_array($payload['args'] ?? null) ? ($payload['args']['list_key'] ?? $payload['args']['key'] ?? '—') : '—',
            ],
            $change->kind === 'layout.requested' => [
                'Layout ID' => $payload['layout_id'] ?? '—',
            ],
            default => [],
        };
    }

    public function approve(string $changeId): void
    {
        $this->resolve($changeId, fn (StudioApprovalService $service, Change $change, ?string $reviewerId) => $service->approve($change, $reviewerId));
    }

    public function reject(string $changeId): void
    {
        $this->resolve($changeId, fn (StudioApprovalService $service, Change $change, ?string $reviewerId) => $service->reject($change, $reviewerId));
    }

    private function resolve(string $changeId, callable $action): void
    {
        $tenant = $this->currentTenant();
        if ($tenant === null) {
            return;
        }

        /** @var PlatformUser|null $reviewer */
        $reviewer = Filament::auth()->user();

        tenancy()->initialize($tenant);

        try {
            $change = Change::query()->findOrFail($changeId);
            $action(app(StudioApprovalService::class), $change, $reviewer?->id);

            $refreshed = $change->fresh();
            $status = $refreshed instanceof Change ? $refreshed->status : $change->status;
            Notification::make()->title("Request {$status}")->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Could not process request')->body($e->getMessage())->danger()->send();
        } finally {
            tenancy()->end();
        }
    }
}
