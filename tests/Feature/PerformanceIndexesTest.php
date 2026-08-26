<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTruncation::class);

/**
 * Z-4.4 index review: these two columns are filtered by range on every dashboard
 * load (DashboardService::callsToMake/attentionNeeded) and had no index at all.
 */
it('indexes leads.next_follow_up_at', function () {
    $indexed = collect(Schema::getIndexes('leads'))
        ->contains(fn (array $index): bool => in_array('next_follow_up_at', $index['columns'], true));

    expect($indexed)->toBeTrue();
});

it('indexes clients.next_action_at', function () {
    $indexed = collect(Schema::getIndexes('clients'))
        ->contains(fn (array $index): bool => in_array('next_action_at', $index['columns'], true));

    expect($indexed)->toBeTrue();
});

/**
 * BACKEND_BRIEF §4's index table requires created_at and phone_mobile on
 * every Contactable entity -- neither was ever indexed on any of them.
 */
it('indexes created_at and phone_mobile on every Contactable table', function (string $table) {
    $indexes = collect(Schema::getIndexes($table));

    expect($indexes->contains(fn (array $index): bool => in_array('created_at', $index['columns'], true)))
        ->toBeTrue("expected $table to index created_at")
        ->and($indexes->contains(fn (array $index): bool => in_array('phone_mobile', $index['columns'], true)))
        ->toBeTrue("expected $table to index phone_mobile");
})->with(['leads', 'companies', 'students', 'clients', 'affiliates', 'newsletter_subscribers']);

/**
 * §4: "leads additionally needs a composite (vertical, stage,
 * assigned_user_id)" -- leads had separate single-column vertical and stage
 * indexes, but never this composite.
 */
it('indexes the leads (vertical, stage, assigned_user_id) composite', function () {
    $indexed = collect(Schema::getIndexes('leads'))->contains(
        fn (array $index): bool => array_slice($index['columns'], 0, 3) === ['vertical', 'stage', 'assigned_user_id'],
    );

    expect($indexed)->toBeTrue();
});

/**
 * Every other status-bearing Contactable table (company_contact_status,
 * students.status, client_status, newsletter_subscribers.status) already
 * indexes its status column; affiliates.status was the one left out.
 */
it('indexes affiliates.status', function () {
    $indexed = collect(Schema::getIndexes('affiliates'))
        ->contains(fn (array $index): bool => in_array('status', $index['columns'], true));

    expect($indexed)->toBeTrue();
});

/**
 * §4's created_at/phone_mobile requirement applies per-column, not only to
 * Contactable entities. `assessments` isn't Contactable (no shared macro)
 * but independently has both columns.
 */
it('indexes created_at and phone_mobile on assessments', function () {
    $indexes = collect(Schema::getIndexes('assessments'));

    expect($indexes->contains(fn (array $index): bool => in_array('created_at', $index['columns'], true)))
        ->toBeTrue()
        ->and($indexes->contains(fn (array $index): bool => in_array('phone_mobile', $index['columns'], true)))
        ->toBeTrue();
});
