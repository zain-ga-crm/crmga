<?php

use Illuminate\Support\Facades\Blade;

// S-1.4: <x-loading-state /> -- a reusable inline spinner for a custom
// Livewire section (Filament's own tables/forms already show a built-in
// loading state on their own). Hidden by default; wire:loading toggles it.
it('renders hidden by default with the default label', function () {
    $html = Blade::render('<x-loading-state />');

    expect($html)->toContain('hidden')
        ->and($html)->toContain('wire:loading.class.remove="hidden"')
        ->and($html)->toContain('Loading…');
});

it('accepts a custom label', function () {
    $html = Blade::render('<x-loading-state label="Saving…" />');

    expect($html)->toContain('Saving…')
        ->and($html)->not->toContain('Loading…');
});
