<?php

use App\Filament\Platform\Resources\TenantResource;
use App\Filament\Platform\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Platform\Resources\TenantResource\Pages\ListTenants;
use App\Filament\Platform\Resources\TenantResource\Pages\ViewTenant;
use App\Models\Metadata\Module;
use App\Models\PlatformUser;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;

// S-8.1/S-8.2: the super-admin panel. Its own guard (config/auth.php's
// 'platform') keeps a platform operator's session entirely separate from a
// tenant's CRM users -- a CRM admin (User::is_admin) has no access here at
// all, and a platform operator has no access to any one tenant's own admin
// panel either.
uses(DatabaseTruncation::class);

// Livewire::test() mounts a page's component directly, without going through
// the real HTTP routing that normally calls Filament::setCurrentPanel() for
// a request's matched panel -- needed here because 'platform' isn't the
// default panel ('admin' is), so Resource::getUrl() would otherwise resolve
// route names against the wrong panel.
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('platform')));

it('denies the panel entirely to a regular CRM user -- the guards are separate', function () {
    promotePrimaryTenant();
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get('/platform')->assertRedirect();
});

it('lists tenants with their domain, status and stats', function () {
    $tenant = promotePrimaryTenant();
    $this->actingAs(PlatformUser::factory()->create(), 'platform');

    Livewire::test(ListTenants::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$tenant])
        ->assertTableColumnExists('domain')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('users')
        ->assertTableColumnExists('records')
        ->assertTableColumnExists('database_size_mb');
});

it('shows a tenant\'s detail view with its real domain and stats', function () {
    $tenant = promotePrimaryTenant();
    $this->actingAs(PlatformUser::factory()->create(), 'platform');

    Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('localhost');
});

it('suspends and resumes a tenant from the list', function () {
    $tenant = promotePrimaryTenant();
    $this->actingAs(PlatformUser::factory()->create(), 'platform');

    $component = Livewire::test(ListTenants::class);

    $component->callTableAction('suspend', $tenant);
    expect(TenantResource::statusOf($tenant->fresh()))->toBe('suspended');

    $component->callTableAction('resume', $tenant->fresh());
    expect(TenantResource::statusOf($tenant->fresh()))->toBe('active');
});

it('blocks API and admin-panel requests to a suspended tenant with a 503', function () {
    $tenant = promotePrimaryTenant();
    $tenant->update(['status' => 'suspended']);

    $response = $this->getJson('/api/v1/leads');

    expect($response->status())->toBe(503);
});

it('still allows requests to an active (non-suspended) tenant', function () {
    promotePrimaryTenant();

    $response = $this->getJson('/api/v1/leads');

    expect($response->status())->not->toBe(503);
});

it('creates a new company end to end through the wizard, seeding full Studio metadata', function () {
    $this->actingAs(PlatformUser::factory()->create(), 'platform');

    Livewire::test(CreateTenant::class)
        ->fillForm([
            'domain' => 'wizard-test.crm.test',
            'admin_name' => 'Wiz Admin',
            'admin_email' => 'wiz@example.test',
            'admin_password' => 'Sup3r$ecretPass1',
        ])
        ->call('create');

    $tenant = Tenant::query()->whereHas('domains', fn ($q) => $q->where('domain', 'wizard-test.crm.test'))->first();
    expect($tenant)->not->toBeNull();

    tenancy()->initialize($tenant);
    try {
        expect(User::query()->where('email', 'wiz@example.test')->exists())->toBeTrue()
            ->and(Module::query()->where('key', 'leads')->exists())->toBeTrue();
    } finally {
        tenancy()->end();
        $tenant->delete();
    }
});
