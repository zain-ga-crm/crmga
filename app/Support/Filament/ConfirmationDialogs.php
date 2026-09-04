<?php

namespace App\Support\Filament;

/**
 * S-1.4: canned modal copy for `Filament\Actions\Action::requiresConfirmation()`,
 * e.g.:
 *
 *   Action::make('delete')
 *       ->requiresConfirmation()
 *       ->modalHeading(ConfirmationDialogs::destructive('lead')['heading'])
 *       ->modalDescription(ConfirmationDialogs::destructive('lead')['description'])
 *       ->modalIcon(ConfirmationDialogs::destructive('lead')['icon']);
 *
 * requiresConfirmation() and modalHeading()/modalDescription()/modalIcon() are
 * all already built into Filament (Concerns\CanRequireConfirmation,
 * Concerns\CanOpenModal) -- this class only fixes the wording/icon convention
 * so every destructive action across every future module reads the same way.
 *
 * @phpstan-type Dialog array{heading: string, description: string, icon: string}
 */
final class ConfirmationDialogs
{
    /**
     * A single-record destructive action (delete, disable, revoke, ...).
     * $subject should read naturally after "Delete " / lowercase, e.g. "this lead".
     *
     * @return Dialog
     */
    public static function destructive(string $subject, string $verb = 'Delete'): array
    {
        return [
            'heading' => "{$verb} {$subject}?",
            'description' => "This can't be undone.",
            'icon' => 'heroicon-o-trash',
        ];
    }

    /**
     * A bulk action across a selected set of records.
     *
     * @return Dialog
     */
    public static function bulkDestructive(string $pluralSubject, string $verb = 'Delete'): array
    {
        return [
            'heading' => "{$verb} the selected {$pluralSubject}?",
            'description' => "This can't be undone.",
            'icon' => 'heroicon-o-trash',
        ];
    }

    /**
     * A non-destructive but consequential action worth a pause (e.g.
     * disabling two-factor authentication) -- a lighter warning tone than
     * destructive(), no "can't be undone" claim since the action is reversible.
     *
     * @return Dialog
     */
    public static function consequential(string $description): array
    {
        return [
            'heading' => 'Are you sure?',
            'description' => $description,
            'icon' => 'heroicon-o-exclamation-triangle',
        ];
    }
}
