<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * S-8.1: the super-admin panel, on the central domain only -- deliberately
 * NOT running InitializeTenancyByDomain/EndTenancyAfterResponse (the admin
 * panel's own middleware), since a platform operator manages tenants from
 * outside any one of them. Its own guard (PlatformUser, config/auth.php's
 * 'platform' guard) keeps this panel's session entirely separate from a
 * tenant's CRM users -- signing into one never signs you into the other.
 */
class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->authGuard('platform')
            ->login()
            ->brandName('crmga platform')
            ->font('Inter')
            ->colors([
                'primary' => Color::hex('#2E74B5'),
                'danger' => Color::hex('#DC2626'),
                'warning' => Color::hex('#D97706'),
                'success' => Color::hex('#059669'),
                'info' => Color::hex('#0891B2'),
            ])
            ->discoverResources(in: app_path('Filament/Platform/Resources'), for: 'App\\Filament\\Platform\\Resources')
            ->discoverPages(in: app_path('Filament/Platform/Pages'), for: 'App\\Filament\\Platform\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->middleware([
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
            ]);
    }
}
