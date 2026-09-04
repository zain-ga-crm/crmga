<?php

namespace App\Support\Filament;

use Filament\Tables\Columns\TextColumn;
use InvalidArgumentException;

/**
 * S-1.4: named business-semantic badge overrides -- distinct from
 * FieldTypeRegistry's generic per-*type* mapping (any `bool` field gets a
 * plain checkbox icon by default). A handful of specific boolean fields carry
 * enough business weight (a hot lead, a do-not-call flag) that a generic
 * checkbox undersells them; this is the deliberately small, named list of
 * which fields get a colored badge instead, and what it says.
 *
 * Not a generic mechanism -- if a future field needs the same treatment, add
 * it to the list below. Enum/multienum badge colouring is unrelated to this
 * class: those come from each option item's own `color` column, read
 * directly by FieldTypeRegistry (any dropdown can carry colours, not just a
 * fixed named list).
 */
final class BadgeRegistry
{
    /** @var array<string, array{label: string, color: string}> */
    private const BOOLEAN_BADGES = [
        'hot_lead' => ['label' => 'Hot', 'color' => 'danger'],
        'warm_lead' => ['label' => 'Warm', 'color' => 'warning'],
        'do_not_call' => ['label' => 'DNC', 'color' => 'danger'],
    ];

    public static function hasBooleanBadge(string $fieldName): bool
    {
        return isset(self::BOOLEAN_BADGES[$fieldName]);
    }

    /**
     * A badge that only renders when the flag is true -- a false value
     * would-be badge ("Not hot") is not itself information worth a colored
     * pill, so the cell is left blank instead.
     */
    public static function booleanBadgeColumn(string $fieldName): TextColumn
    {
        $config = self::BOOLEAN_BADGES[$fieldName]
            ?? throw new InvalidArgumentException("No badge defined for boolean field [{$fieldName}]. Check hasBooleanBadge() first.");

        return TextColumn::make($fieldName)
            ->badge(fn (mixed $state): bool => (bool) $state)
            ->color($config['color'])
            ->formatStateUsing(fn (mixed $state): string => $state ? $config['label'] : '');
    }
}
