<?php

use App\Support\Filament\ConfirmationDialogs;

it('builds a destructive-action dialog naming the subject, claiming it cannot be undone', function () {
    $dialog = ConfirmationDialogs::destructive('this lead');

    expect($dialog['heading'])->toBe('Delete this lead?')
        ->and($dialog['description'])->toContain("can't be undone")
        ->and($dialog['icon'])->toBe('heroicon-o-trash');
});

it('lets a destructive dialog use a verb other than delete', function () {
    $dialog = ConfirmationDialogs::destructive('this subscription', verb: 'Cancel');

    expect($dialog['heading'])->toBe('Cancel this subscription?');
});

it('builds a bulk-destructive dialog for a plural subject', function () {
    $dialog = ConfirmationDialogs::bulkDestructive('leads');

    expect($dialog['heading'])->toBe('Delete the selected leads?')
        ->and($dialog['description'])->toContain("can't be undone");
});

it('builds a consequential-but-reversible dialog with a lighter tone, no undo claim', function () {
    $dialog = ConfirmationDialogs::consequential('This will require you to re-enroll two-factor authentication.');

    expect($dialog['heading'])->toBe('Are you sure?')
        ->and($dialog['description'])->not->toContain("can't be undone")
        ->and($dialog['icon'])->toBe('heroicon-o-exclamation-triangle');
});
