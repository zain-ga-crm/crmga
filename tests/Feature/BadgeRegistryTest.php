<?php

use App\Support\Filament\BadgeRegistry;
use Filament\Tables\Columns\TextColumn;

it('lists the three named boolean fields that get a badge', function (string $name) {
    expect(BadgeRegistry::hasBooleanBadge($name))->toBeTrue();
})->with(['hot_lead', 'warm_lead', 'do_not_call']);

it('reports false for a boolean field with no named badge', function () {
    expect(BadgeRegistry::hasBooleanBadge('is_archived'))->toBeFalse();
});

it('throws for a field with no configured badge, rather than building a broken column silently', function () {
    BadgeRegistry::booleanBadgeColumn('is_archived');
})->throws(InvalidArgumentException::class);

it('builds a badge column with the right label and color per field, shown only when true', function () {
    $column = BadgeRegistry::booleanBadgeColumn('do_not_call');

    expect($column)->toBeInstanceOf(TextColumn::class);

    $color = (new ReflectionProperty(TextColumn::class, 'color'))->getValue($column);
    expect($color)->toBe('danger');

    expect($column->formatState(true))->toBe('DNC')
        ->and($column->formatState(false))->toBe('');
});
