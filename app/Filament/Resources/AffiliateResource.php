<?php

namespace App\Filament\Resources;

use App\Filament\Exports\AffiliateExporter;
use App\Filament\Resources\AffiliateResource\Pages;
use App\Filament\Resources\Concerns\BuildsResourceFromMetadata;
use App\Models\Affiliate;
use Filament\Actions\Exports\Exporter;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * S-4.1: another DynamicResource -- everything here comes from the
 * 'affiliates' module's own tenant_fields/tenant_layouts, same as Lead/Company.
 */
class AffiliateResource extends Resource
{
    use BuildsResourceFromMetadata;

    protected static ?string $model = Affiliate::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'Directory';

    public static function moduleKey(): string
    {
        return 'affiliates';
    }

    /**
     * @return class-string<Exporter>
     */
    public static function exporter(): ?string
    {
        return AffiliateExporter::class;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAffiliates::route('/'),
            'create' => Pages\CreateAffiliate::route('/create'),
            'view' => Pages\ViewAffiliate::route('/{record}'),
            'edit' => Pages\EditAffiliate::route('/{record}/edit'),
        ];
    }
}
