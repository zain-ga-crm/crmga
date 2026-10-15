<?php

namespace App\Support\Studio;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Stancl\Tenancy\Resolvers\DomainTenantResolver;
use Throwable;

/**
 * S-8.3: per-company Studio governance. 'disabled' blocks every Studio page
 * outright; 'request_only' lets an admin stage a change but queues it as a
 * Change row with status 'requested' instead of applying it -- a platform
 * operator must approve it (StudioApprovals) before it runs for real;
 * 'self_service' is the pre-S-8.3 behaviour, unchanged, and the default for
 * every tenant that has never set a mode (including tenant #1, promoted
 * before this existed).
 *
 * Mode lives on the central Tenant's own 'data' JSON column (the same
 * HasDataColumn/VirtualColumn proxy TenantResource::statusOf() already reads
 * 'status' through) -- no migration needed, central or tenant-side.
 *
 * currentMode() can't simply read tenancy()->tenant: a Studio page's actual
 * submit actions (FieldManager::submitFieldChange() and the rest) run inside
 * Livewire's own generic update request (POST /livewire/update), which the
 * admin panel's own InitializeTenancyByDomain middleware never touches --
 * confirmed via `route:list -vv --path=livewire`, that route carries only
 * Livewire's default 'web' group, nothing tenancy-related. So tenancy()
 * ->tenant is null there even mid-session, and a naive read would silently
 * treat every gated request as self_service. Falling back to resolving the
 * tenant from the SAME request's own Host header (exactly what
 * InitializeTenancyByDomain itself would have resolved, had it run) closes
 * that gap without touching Livewire's routing globally.
 */
final class StudioGovernance
{
    public const MODE_DISABLED = 'disabled';

    public const MODE_REQUEST_ONLY = 'request_only';

    public const MODE_SELF_SERVICE = 'self_service';

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_DISABLED, self::MODE_REQUEST_ONLY, self::MODE_SELF_SERVICE];
    }

    public static function modeOf(Tenant $tenant): string
    {
        $mode = $tenant->getAttribute('studio_mode');

        return is_string($mode) && in_array($mode, self::modes(), true) ? $mode : self::MODE_SELF_SERVICE;
    }

    /**
     * The mode of whichever tenant the CURRENT request is running inside --
     * every Studio page call site gates against this, never against a tenant
     * the caller passes in, since Studio only ever runs inside one tenant's
     * own admin panel.
     */
    public static function currentMode(): string
    {
        $tenant = tenancy()->tenant ?? self::resolveTenantFromRequestHost();

        return $tenant instanceof Tenant ? self::modeOf($tenant) : self::MODE_SELF_SERVICE;
    }

    private static function resolveTenantFromRequestHost(): ?Tenant
    {
        /** @var Request|null $request */
        $request = app()->bound('request') ? request() : null;
        $host = $request?->getHost();
        if ($host === null || $host === '') {
            return null;
        }

        try {
            $tenant = app(DomainTenantResolver::class)->resolve($host);
        } catch (Throwable) {
            return null;
        }

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public static function isDisabled(): bool
    {
        return self::currentMode() === self::MODE_DISABLED;
    }

    public static function shouldQueue(): bool
    {
        return self::currentMode() === self::MODE_REQUEST_ONLY;
    }
}
