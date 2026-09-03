<?php

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Support\Colors\Color;

// S-1.1 -- the panel shell (crmga_Frontend_Design_Spec.docx §2, §3.1, §4.1): brand, theme
// colors, navigation groups and the notification bell. No modules/resources yet.
it('brands the panel and sets the design-spec colors', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getBrandName())->toBe('crmga')
        ->and($panel->getFontFamily())->toBe('Inter');

    $colors = $panel->getColors();
    expect($colors['primary'])->toBe(Color::hex('#2E74B5'))
        ->and($colors['danger'])->toBe(Color::hex('#DC2626'))
        ->and($colors['warning'])->toBe(Color::hex('#D97706'))
        ->and($colors['success'])->toBe(Color::hex('#059669'))
        ->and($colors['info'])->toBe(Color::hex('#0891B2'));
});

it('registers the sidebar groups from the design spec, in order', function () {
    $groups = array_map(
        fn (NavigationGroup $group): string => $group->getLabel(),
        Filament::getPanel('admin')->getNavigationGroups(),
    );

    expect($groups)->toBe([
        'Sales & Intake',
        'Directory',
        'Delivery',
        'Communication',
        'Lists',
        'Administration',
    ]);
});

it('enables the notification bell', function () {
    expect(Filament::getPanel('admin')->hasDatabaseNotifications())->toBeTrue();
});

it('sets the sidebar width, collapsed-icon mode, and content max-width from the design spec', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getSidebarWidth())->toBe('15.5rem')
        ->and($panel->getCollapsedSidebarWidth())->toBe('4rem')
        ->and($panel->isSidebarCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->getMaxContentWidth())->toBe('max-w-[1600px]');
});
