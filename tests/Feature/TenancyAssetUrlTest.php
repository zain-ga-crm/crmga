<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;

// Regression: FilesystemTenancyBootstrapper rewrites app.asset_url (and asset()'s
// root) whenever 'tenancy.filesystem.asset_helper_tenancy' is true, completely
// independent of whether 'public' is even in the 'disks' list. Found live: once
// a real tenant is active, every compiled Vite asset resolved under
// ".../tenant{id}/build/..." instead of ".../build/...", 404ing every panel
// view's stylesheet -- latent since Phase 1 because no Vite asset existed
// before S-1.1's panel-shell theme.
uses(DatabaseTruncation::class);

it('does not rewrite the asset URL when a tenant is initialized', function () {
    $tenant = promotePrimaryTenant();
    $originalAssetUrl = config('app.asset_url');

    tenancy()->initialize($tenant);

    expect(config('app.asset_url'))->toBe($originalAssetUrl)
        ->and(asset('build/x'))->not->toContain('tenant'.$tenant->getTenantKey());
});
