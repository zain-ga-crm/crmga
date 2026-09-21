<?php

use App\Filament\Exports\StudentExporter;
use App\Filament\Resources\StudentResource;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Student;
use App\Models\User;
use App\Support\Acl\AccessLevel;
use Database\Seeders\MetadataFixtureSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// S-4.1: StudentResource is another DynamicResource -- coverage deliberately
// mirrors CompanyResourceTest rather than re-deriving it, since it exercises
// the same shared BuildsResourceFromMetadata mechanism.
uses(DatabaseTruncation::class);

beforeEach(function () {
    Cache::flush();
    promotePrimaryTenant();
    $this->seed(MetadataFixtureSeeder::class);
});

it('lists students with the list layout\'s columns, for a user with All access', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $students = Student::factory()->count(3)->create();

    $this->actingAs($admin);

    Livewire::test(ListStudents::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($students)
        ->assertTableColumnExists('full_name')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('primary_email')
        ->assertTableColumnExists('phone_mobile')
        ->assertTableColumnExists('assignedUser.name');
});

it('scopes the students list to only the owner\'s own records for an Owner-access user', function () {
    $user = User::factory()->create();
    grantAccess($user, 'students', AccessLevel::Owner, 'list');
    grantAccess($user, 'students', AccessLevel::Owner, 'view');

    $own = Student::factory()->create(['assigned_user_id' => $user->id]);
    $someoneElses = Student::factory()->create();

    $this->actingAs($user);

    Livewire::test(ListStudents::class)
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$someoneElses]);
});

it('denies the list page entirely to a user with no students access at all', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get(StudentResource::getUrl('index'))->assertForbidden();
});

it('creates a student end to end through the create form', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin);

    Livewire::test(CreateStudent::class)
        ->fillForm([
            'first_name' => 'Priya',
            'last_name' => 'Sharma',
            'primary_email' => 'priya@example.test',
            'status' => 'Enrolled',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Student::query()->where('primary_email', 'priya@example.test')->exists())->toBeTrue();
});

it('exports students using the same list-layout columns the table shows', function () {
    $columns = collect(StudentExporter::getColumns())
        ->map(fn ($column) => $column->getName())
        ->all();

    expect($columns)->toContain('full_name', 'status', 'primary_email', 'phone_mobile', 'assignedUser.name');
});
