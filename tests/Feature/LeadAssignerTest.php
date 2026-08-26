<?php

use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Support\Ingest\LeadAssigner;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(DatabaseTruncation::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['is_admin' => true])));

function assignerSalesRep(): User
{
    $role = Role::query()->firstOrCreate(['name' => 'Sales Representatives']);
    $user = User::factory()->create(['status' => 'active']);
    $user->roles()->attach($role);

    return $user;
}

it('round-robins across active sales reps in id order, wrapping around', function () {
    $first = assignerSalesRep();
    $second = assignerSalesRep();
    $ordered = collect([$first, $second])->sortBy('id')->values();

    $assigner = app(LeadAssigner::class);

    $leadA = Lead::factory()->create(['assigned_user_id' => null]);
    $assigner->assign($leadA);
    $leadB = Lead::factory()->create(['assigned_user_id' => null]);
    $assigner->assign($leadB);
    $leadC = Lead::factory()->create(['assigned_user_id' => null]);
    $assigner->assign($leadC);

    expect($leadA->fresh()->assigned_user_id)->toBe($ordered[0]->id)
        ->and($leadB->fresh()->assigned_user_id)->toBe($ordered[1]->id)
        ->and($leadC->fresh()->assigned_user_id)->toBe($ordered[0]->id);
});

it('leaves a lead unassigned when there is no active sales rep', function () {
    $lead = Lead::factory()->create(['assigned_user_id' => null]);

    app(LeadAssigner::class)->assign($lead);

    expect($lead->fresh()->assigned_user_id)->toBeNull();
});

it('leaves a lead unassigned rather than failing when the cursor lock is already held', function () {
    assignerSalesRep();
    Log::shouldReceive('channel')->with('api')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('lead_assignment_lock_timeout', Mockery::type('array'));

    $lock = Cache::lock('ingest.assignment.leads.lock', 10);
    expect($lock->get())->toBeTrue();

    try {
        $lead = Lead::factory()->create(['assigned_user_id' => null]);
        app(LeadAssigner::class)->assign($lead);

        expect($lead->fresh()->assigned_user_id)->toBeNull();
    } finally {
        $lock->release();
    }
})->group('slow');
