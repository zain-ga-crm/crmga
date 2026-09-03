<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Http\Middleware\EndTenancyAfterResponse;
use App\Http\Middleware\ForcePasswordChange;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->passwordReset()
            ->brandName('crmga')
            ->font('Inter')
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Values from crmga_Frontend_Design_Spec.docx §2.1 -- brand-500 is the primary
            // action/link color; the semantic four map 1:1 to Filament's own palette slots.
            ->colors([
                'primary' => Color::hex('#2E74B5'),
                'danger' => Color::hex('#DC2626'),
                'warning' => Color::hex('#D97706'),
                'success' => Color::hex('#059669'),
                'info' => Color::hex('#0891B2'),
            ])
            // Groups from §4.1's sidebar map. Empty groups render nothing until Phase 2's
            // module Resources register into them by name -- this call only fixes the order.
            ->navigationGroups([
                NavigationGroup::make('Sales & Intake'),
                NavigationGroup::make('Directory'),
                NavigationGroup::make('Delivery'),
                NavigationGroup::make('Communication'),
                NavigationGroup::make('Lists'),
                NavigationGroup::make('Administration')->collapsed(),
            ])
            ->databaseNotifications()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                // Z-8.3 -- must run before StartSession: DatabaseTenancyBootstrapper
                // switches the DB connection sessions are stored on.
                InitializeTenancyByDomain::class,
                EndTenancyAfterResponse::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                ForcePasswordChange::class,
            ]);
    }
}
