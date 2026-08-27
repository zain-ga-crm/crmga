<?php

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;

uses(DatabaseTruncation::class);

it('seeds all 28 real starter roles, reconciled against the live acl_roles dump', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::query()->count())->toBe(28)
        ->and(Role::query()->where('name', 'Administrator')->value('is_system'))->toBeTrue()
        ->and(Role::query()->where('name', 'Appointment Setter - Regular User New')->exists())->toBeTrue();
});

it('is idempotent -- running twice does not duplicate roles', function () {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    expect(Role::query()->count())->toBe(28);
});
