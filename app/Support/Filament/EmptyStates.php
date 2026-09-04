<?php

namespace App\Support\Filament;

/**
 * S-1.4: the empty-state wording/icon convention every future list screen
 * (S-2.1 DynamicResource and any Filament\Tables\Table) should apply, e.g.:
 *
 *   $table->emptyStateHeading(EmptyStates::heading($module->label_plural))
 *       ->emptyStateDescription(EmptyStates::description())
 *       ->emptyStateIcon(EmptyStates::icon());
 *
 * Filament's Table already provides emptyStateHeading()/Description()/Icon()/
 * Actions() (Concerns\HasEmptyState) -- this class only fixes the copy/icon
 * convention so every module's empty list reads the same way, rather than
 * each resource inventing its own wording.
 */
final class EmptyStates
{
    public static function heading(string $labelPlural): string
    {
        return "No {$labelPlural} yet";
    }

    public static function description(): string
    {
        return "Records will show up here once they're created.";
    }

    public static function icon(): string
    {
        return 'heroicon-o-inbox';
    }

    /**
     * For a filtered/searched list that's empty because nothing matched --
     * distinct from a genuinely empty module, so the user knows to check
     * their filters rather than assume there's no data at all.
     */
    public static function noSearchResultsHeading(): string
    {
        return 'No matching records';
    }

    public static function noSearchResultsDescription(): string
    {
        return 'Try a different search term or clear your filters.';
    }

    public static function noSearchResultsIcon(): string
    {
        return 'heroicon-o-magnifying-glass';
    }
}
