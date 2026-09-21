<?php

use App\Models\Metadata\Change;
use App\Support\Filament\ChangeDescriber;

it('describes a field.add change', function () {
    $change = Change::factory()->make([
        'kind' => 'field.add',
        'target_module' => 'leads',
        'target_field' => 'source',
        'payload' => ['before' => null, 'after' => ['type' => 'text']],
    ]);

    expect(ChangeDescriber::describe($change))->toBe('Added field [source] (text) to [leads].');
});

it('describes a field.modify change with a diff of the changed attributes', function () {
    $change = Change::factory()->make([
        'kind' => 'field.modify',
        'target_module' => 'leads',
        'target_field' => 'source',
        'payload' => [
            'before' => ['label' => 'Source', 'required' => false],
            'after' => ['label' => 'Lead source', 'required' => false],
        ],
    ]);

    expect(ChangeDescriber::describe($change))->toBe("Changed field [source] on [leads] (label: Source \u{2192} Lead source).");
});

it('describes a field.modify change with no diff as a plain sentence', function () {
    $change = Change::factory()->make([
        'kind' => 'field.modify',
        'target_module' => 'leads',
        'target_field' => 'source',
        'payload' => ['before' => ['label' => 'Source'], 'after' => ['label' => 'Source']],
    ]);

    expect(ChangeDescriber::describe($change))->toBe('Changed field [source] on [leads].');
});

it('describes a field.delete change', function () {
    $change = Change::factory()->make([
        'kind' => 'field.delete',
        'target_module' => 'leads',
        'target_field' => 'source',
        'payload' => ['before' => ['label' => 'Source'], 'after' => null],
    ]);

    expect(ChangeDescriber::describe($change))->toBe('Deleted field [source] from [leads].');
});

it('describes layout.drafted and layout.published changes', function () {
    $drafted = Change::factory()->make([
        'kind' => 'layout.drafted',
        'target_module' => 'leads',
        'target_field' => 'list',
        'payload' => ['before' => null, 'after' => ['version' => 3]],
    ]);
    $published = Change::factory()->make([
        'kind' => 'layout.published',
        'target_module' => 'leads',
        'target_field' => 'list',
        'payload' => ['before' => ['version' => 2], 'after' => ['version' => 3]],
    ]);

    expect(ChangeDescriber::describe($drafted))->toBe('Drafted version 3 of the [list] layout for [leads].')
        ->and(ChangeDescriber::describe($published))->toBe('Published version 3 of the [list] layout for [leads].');
});

it('describes optionlist.created and optionlist.renamed changes', function () {
    $created = Change::factory()->make([
        'kind' => 'optionlist.created',
        'target_module' => 'lead_temperature',
        'payload' => ['before' => null, 'after' => ['label' => 'Lead temperature']],
    ]);
    $renamed = Change::factory()->make([
        'kind' => 'optionlist.renamed',
        'target_module' => 'lead_temperature',
        'payload' => ['before' => ['label' => 'Old'], 'after' => ['label' => 'New']],
    ]);

    expect(ChangeDescriber::describe($created))->toBe('Created option list [lead_temperature] (Lead temperature).')
        ->and(ChangeDescriber::describe($renamed))->toBe('Renamed option list [lead_temperature] from [Old] to [New].');
});

it('describes option.added, option.updated, option.removed and option.reordered changes', function () {
    $added = Change::factory()->make([
        'kind' => 'option.added', 'target_module' => 'lead_stage', 'target_field' => 'gold',
        'payload' => ['before' => null, 'after' => ['label' => 'Gold']],
    ]);
    $updated = Change::factory()->make([
        'kind' => 'option.updated', 'target_module' => 'lead_stage', 'target_field' => 'gold',
        'payload' => ['before' => ['label' => 'Gold'], 'after' => ['label' => 'Gold tier']],
    ]);
    $removed = Change::factory()->make([
        'kind' => 'option.removed', 'target_module' => 'lead_stage', 'target_field' => 'gold',
        'payload' => ['before' => ['label' => 'Gold'], 'after' => null],
    ]);
    $reordered = Change::factory()->make([
        'kind' => 'option.reordered', 'target_module' => 'lead_stage',
        'payload' => ['before' => ['gold', 'silver'], 'after' => ['silver', 'gold']],
    ]);

    expect(ChangeDescriber::describe($added))->toBe('Added option [gold] (Gold) to list [lead_stage].')
        ->and(ChangeDescriber::describe($updated))->toBe("Updated option [gold] on list [lead_stage] (label: Gold \u{2192} Gold tier).")
        ->and(ChangeDescriber::describe($removed))->toBe('Removed option [gold] from list [lead_stage].')
        ->and(ChangeDescriber::describe($reordered))->toBe('Reordered the options on list [lead_stage].');
});

it('falls back to a generic sentence for an unrecognized kind', function () {
    $change = Change::factory()->make(['kind' => 'something.else', 'target_module' => 'leads', 'target_field' => 'x']);

    expect(ChangeDescriber::describe($change))->toBe('[something.else] on [leads/x].');
});
