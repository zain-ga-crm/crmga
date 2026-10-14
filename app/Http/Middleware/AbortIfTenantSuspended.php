<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * S-8.1: makes "Suspend" on the platform panel's companies list a real
 * block, not just a label -- without this, suspending a tenant would change
 * a status field nobody's request path ever reads. Runs after
 * InitializeTenancyByDomain (tenant() is only resolvable once that's run)
 * and before anything else touches the tenant's database.
 */
final class AbortIfTenantSuspended
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (tenant('status') === 'suspended') {
            abort(503, 'This company\'s account is currently suspended.');
        }

        return $next($request);
    }
}
