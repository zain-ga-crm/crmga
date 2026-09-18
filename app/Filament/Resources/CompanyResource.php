<?php

namespace App\Filament\Resources;

use App\Filament\Exports\CompanyExporter;
use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\Concerns\BuildsResourceFromMetadata;
use App\Models\Company;
use Filament\Actions\Exports\Exporter;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * S-2.2: the second DynamicResource, proving BuildsResourceFromMetadata
 * generalises past leads -- everything here comes from the 'companies'
 * module's own tenant_fields/tenant_layouts, same as LeadResource.
 */
class CompanyResource extends Resource
{
    use BuildsResourceFromMetadata;

    protected static ?string $model = Company::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationGroup = 'Directory';

    public static function moduleKey(): string
    {
        return 'companies';
    }

    /**
     * @return class-string<Exporter>
     */
    public static function exporter(): ?string
    {
        return CompanyExporter::class;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCompanies::route('/'),
            'create' => Pages\CreateCompany::route('/create'),
            'view' => Pages\ViewCompany::route('/{record}'),
            'edit' => Pages\EditCompany::route('/{record}/edit'),
        ];
    }
}
