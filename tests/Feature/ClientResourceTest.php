<?php

use App\Filament\Exports\ClientExporter;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\ListClients;
use App\Models\Client;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-4.1: ClientResource is another DynamicResource -- coverage deliberately
// mirrors CompanyResourceTest rather than re-deriving it.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('lists clients with the list layout\'s columns, for a user with All access', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $clients = Client::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListClients::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($clients)
        ->assertTableColumnExists('full_name')
        ->assertTableColumnExists('client_status')
        ->assertTableColumnExists('case_type')
        ->assertTableColumnExists('primary_email')
        ->assertTableColumnExists('assignedUser.name');
});

it('scopes the clients list to only the owner\'s own records for an Owner-access user', function () {
    $user = User::factory()->create();
    grantAccess($user, 'clients', AccessLevel::Owner, 'list');
    grantAccess($user, 'clients', AccessLevel::Owner, 'view');

    $own = Client::factory()->create(['assigned_user_id' => $user->id]);
    $someoneElses = Client::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('denies the list page entirely to a user with no clients access at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(ClientResource::getUrl('index'))->assertForbidden();
});

it('creates a client end to end through the create form', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CreateClient::class)
        ->fillForm([
            'first_name' => 'Amara',
            'last_name' => 'Okafor',
            'primary_email' => 'amara@example.test',
            'client_status' => 'Active',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Client::query()->where('primary_email', 'amara@example.test')->exists())->toBeTrue();
});

it('exports clients using the same list-layout columns the table shows', function () {
    $columns = collect(ClientExporter::getColumns())
        ->map(fn ($column) => $column->getName())
        ->all();

    expect($columns)->toContain('full_name', 'client_status', 'case_type', 'primary_email', 'assignedUser.name');
});
