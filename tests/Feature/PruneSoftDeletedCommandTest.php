<?php

use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;

uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

it('reports records past the retention window without deleting them when --force is not passed', function () {
    $old = Lead::factory()->create();
    $old->delete();
    $old->forceFill(['deleted_at' => now()->subDays(91)])->saveQuietly();

    $recent = Lead::factory()->create();
    $recent->delete();

    $this->artisan('crm:prune-soft-deleted')
        ->assertExitCode(0)
        ->expectsOutputToContain('Re-run with --force');

    expect(Lead::withTrashed()->withoutGlobalScopes()->count())->toBe(2);
});

it('hard-deletes only records past the retention window when --force is passed', function () {
    $old = Company::factory()->create();
    $old->delete();
    $old->forceFill(['deleted_at' => now()->subDays(91)])->saveQuietly();

    $recent = Company::factory()->create();
    $recent->delete();

    $this->artisan('crm:prune-soft-deleted', ['--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Hard-deleted 1 record');

    expect(Company::withTrashed()->withoutGlobalScopes()->find($old->id))->toBeNull()
        ->and(Company::withTrashed()->withoutGlobalScopes()->find($recent->id))->not->toBeNull();
});

it('reports nothing to do when no soft-deleted record is past the window', function () {
    $this->artisan('crm:prune-soft-deleted')
        ->assertExitCode(0)
        ->expectsOutputToContain('nothing to do');
});
