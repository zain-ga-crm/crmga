<?php

use App\Filament\Exports\AssessmentExporter;
use App\Filament\Resources\AssessmentResource;
use App\Filament\Resources\AssessmentResource\Pages\CreateAssessment;
use App\Filament\Resources\AssessmentResource\Pages\ListAssessments;
use App\Filament\Resources\AssessmentResource\Pages\ViewAssessment;
use App\Filament\Widgets\AssessmentScorecardWidget;
use App\Models\Assessment;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-4.2: the Express Entry CRS/FSW scorecard, another DynamicResource --
// everything comes from the 'assessments' module's own tenant_fields/
// tenant_layouts, same mechanism Company/Lead/Student already prove. Unlike
// those six, Assessment has no activity relation managers/timeline footer
// (see AssessmentResource's own docblock), so coverage here mirrors
// CompanyResourceTest minus that slice.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('lists assessments with the list layout\'s columns, for a user with All access', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $assessments = Assessment::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListAssessments::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($assessments)
        ->assertTableColumnExists('first_name')
        ->assertTableColumnExists('last_name')
        ->assertTableColumnExists('case_type')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('crs_score')
        ->assertTableColumnExists('fsw_score')
        ->assertTableColumnExists('assignedUser.name');
});

it('scopes the assessments list to only the owner\'s own records for an Owner-access user', function () {
    $user = User::factory()->create();
    grantAccess($user, 'assessments', AccessLevel::Owner, 'list');
    grantAccess($user, 'assessments', AccessLevel::Owner, 'view');

    $own = Assessment::factory()->create(['assigned_user_id' => $user->id]);
    $someoneElses = Assessment::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListAssessments::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('denies the list page entirely to a user with no assessments access at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(AssessmentResource::getUrl('index'))->assertForbidden();
});

it('shows the detail view via infolist with real field values', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $assessment = Assessment::factory()->create(['primary_email' => 'candidate@example.com']);

    $this->actingAs($admin);

    $this->get(AssessmentResource::getUrl('view', ['record' => $assessment]))
        ->assertSuccessful()
        ->assertSee('candidate@example.com');
});

it('shows the scorecard widget\'s summary and factor breakdown', function () {
    // Footer widgets are Filament\Support\Concerns\CanBeLazy (lazy by
    // default) -- a plain HTTP get on the view page only ever renders the
    // placeholder, never the nested lazy widget's real content, so this
    // exercises the widget directly, same as ActivityTimelineTest.php does
    // for its own lazy footer widget.
    $assessment = Assessment::factory()->create([
        'crs_score' => 467,
        'fsw_score' => null,
        'scores' => ['household_income' => '1', 'country' => 'Pakistan'],
    ]);

    Livewire::test(AssessmentScorecardWidget::class, ['record' => $assessment])
        ->assertSuccessful()
        ->assertSee('467')
        ->assertSee('Household Income')
        ->assertSee('Pakistan');
});

it('mounts the assessment view page successfully with its footer widget registered', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $assessment = Assessment::factory()->create();

    $this->actingAs($admin);

    Livewire::test(ViewAssessment::class, ['record' => $assessment->getRouteKey()])
        ->assertSuccessful();
});

it('creates an assessment end to end through the create form', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CreateAssessment::class)
        ->fillForm([
            'first_name' => 'Amina',
            'last_name' => 'Khan',
            'primary_email' => 'amina@example.com',
            'case_type' => 'crs',
            'status' => 'requested',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Assessment::query()->where('primary_email', 'amina@example.com')->exists())->toBeTrue();
});

it('exports assessments using the same list-layout columns the table shows', function () {
    $columns = collect(AssessmentExporter::getColumns())
        ->map(fn ($column) => $column->getName())
        ->all();

    expect($columns)->toContain('first_name', 'last_name', 'case_type', 'status', 'crs_score', 'fsw_score', 'assignedUser.name');
});
