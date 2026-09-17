<?php

use App\Support\Filament\EmptyStates;

it('builds a consistent empty-module message from the module label', function () {
    expect(EmptyStates::heading('Leads'))->toBe('No Leads yet')
        ->and(EmptyStates::description())->not->toBeEmpty()
        ->and(EmptyStates::icon())->toBe('heroicon-o-inbox');
});

it('builds a distinct message for a search/filter with no matches', function () {
    expect(EmptyStates::noSearchResultsHeading())->not->toBe(EmptyStates::heading('Leads'))
        ->and(EmptyStates::noSearchResultsIcon())->not->toBe(EmptyStates::icon());
});
