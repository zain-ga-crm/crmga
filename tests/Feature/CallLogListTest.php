<?php

use App\Filament\Pages\CallLogList;
use App\Filament\Resources\LeadResource;
use App\Models\Call;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use Livewire\Livewire;

// S-4.1's deferred Call log screen. Call is polymorphic (morphs to any of
// the six Contactable modules), so this is a plain page over Call::query()
// directly (DoNotCallList/HotWarmList's own pattern), not a
// BuildsResourceFromMetadata resource -- that trait assumes one fixed
// module per resource, which a cross-module activity log doesn't have.
uses(DatabaseTruncation::class);

beforeEach(fn () => promotePrimaryTenant());

it('denies the page to a non-admin user', function () {
    $this->actingAs(User::factory()->create());

    $this->get(CallLogList::getUrl())->assertForbidden();
});

it('allows the page to an admin user', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(CallLogList::getUrl())->assertSuccessful();
});

it('lists calls across every module, newest first, with a working view link to the subject', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $lead = Lead::factory()->create();
    $older = Call::create([
        'subject_type' => Lead::class, 'subject_id' => $lead->id,
        'direction' => 'inbound', 'date_start' => now()->subDay(), 'outcome' => 'connected',
    ]);
    $newer = Call::create([
        'subject_type' => Lead::class, 'subject_id' => $lead->id,
        'direction' => 'outbound', 'date_start' => now(), 'outcome' => 'no_answer',
    ]);

    Livewire::test(CallLogList::class)
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertTableActionHasUrl('viewSubject', LeadResource::getUrl('view', ['record' => $lead]), record: $older);
});

it('shows a placeholder instead of crashing when a call\'s subject no longer exists', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    Call::create([
        'subject_type' => Lead::class, 'subject_id' => (string) Str::uuid(),
        'direction' => 'inbound', 'date_start' => now(),
    ]);

    Livewire::test(CallLogList::class)->assertSuccessful();
});
