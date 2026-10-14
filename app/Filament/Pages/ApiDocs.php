<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * S-5.3: OpenAPI-driven documentation with a try-it console. The spec itself
 * (public/api-docs/openapi.yaml) is static, hand-written from
 * docs/contracts/api-contract.md -- the frozen source of truth this
 * implements -- rather than generated, since the real API is metadata-driven
 * (a module's actual fields come from `GET /meta/modules/{module}/fields`,
 * documented in the spec as its own endpoint rather than baked in per field).
 *
 * Swagger UI (loaded from a CDN, no new composer/npm dependency) renders the
 * spec and provides "Try it out" + its own Authorize dialog for a real bearer
 * token, calling the real API directly from the browser.
 */
class ApiDocs extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static string $view = 'filament.pages.api-docs';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'API Docs';

    protected static ?string $slug = 'api-docs';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'API Documentation';
    }
}
