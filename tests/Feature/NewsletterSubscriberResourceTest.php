<?php

use App\Filament\Exports\NewsletterSubscriberExporter;
use App\Filament\Resources\NewsletterSubscriberResource;
use App\Filament\Resources\NewsletterSubscriberResource\Pages\CreateNewsletterSubscriber;
use App\Filament\Resources\NewsletterSubscriberResource\Pages\ListNewsletterSubscribers;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-4.1: NewsletterSubscriberResource is another DynamicResource -- coverage
// deliberately mirrors CompanyResourceTest rather than re-deriving it.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('lists subscribers with the list layout\'s columns, for a user with All access', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $subscribers = NewsletterSubscriber::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListNewsletterSubscribers::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($subscribers)
        ->assertTableColumnExists('full_name')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('primary_email')
        ->assertTableColumnExists('assignedUser.name');
});

it('scopes the subscribers list to only the owner\'s own records for an Owner-access user', function () {
    $user = User::factory()->create();
    grantAccess($user, 'newsletter_subscribers', AccessLevel::Owner, 'list');
    grantAccess($user, 'newsletter_subscribers', AccessLevel::Owner, 'view');

    $own = NewsletterSubscriber::factory()->create(['assigned_user_id' => $user->id]);
    $someoneElses = NewsletterSubscriber::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListNewsletterSubscribers::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('denies the list page entirely to a user with no newsletter_subscribers access at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(NewsletterSubscriberResource::getUrl('index'))->assertForbidden();
});

it('creates a subscriber end to end through the create form', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CreateNewsletterSubscriber::class)
        ->fillForm([
            'first_name' => 'Noor',
            'last_name' => 'Haddad',
            'primary_email' => 'noor@example.test',
            'status' => 'Subscribed',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(NewsletterSubscriber::query()->where('primary_email', 'noor@example.test')->exists())->toBeTrue();
});

it('creates a subscriber without specifying status, falling back to the column default', function () {
    // Regression test for the bug AffiliateResourceTest's own create test found (S-4.1):
    // a NOT NULL column with only a DB default rejects the explicit NULL Filament's
    // create form submits for an untouched, non-required field -- fixed by migration
    // 2026_09_21_000001 making both affiliates.status and this column nullable.
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CreateNewsletterSubscriber::class)
        ->fillForm([
            'first_name' => 'Casey',
            'last_name' => 'Reyes',
            'primary_email' => 'casey@example.test',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(NewsletterSubscriber::query()->where('primary_email', 'casey@example.test')->exists())->toBeTrue();
});

it('exports subscribers using the same list-layout columns the table shows', function () {
    $columns = collect(NewsletterSubscriberExporter::getColumns())
        ->map(fn ($column) => $column->getName())
        ->all();

    expect($columns)->toContain('full_name', 'status', 'primary_email', 'assignedUser.name');
});
