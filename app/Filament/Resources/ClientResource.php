<?php

namespace App\Filament\Resources;

use App\Filament\Exports\ClientExporter;
use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\Concerns\BuildsResourceFromMetadata;
use App\Models\Client;
use Filament\Actions\Exports\Exporter;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * S-4.1: another DynamicResource -- everything here comes from the 'clients'
 * module's own tenant_fields/tenant_layouts, same as Lead/Company.
 */
class ClientResource extends Resource
{
    use BuildsResourceFromMetadata;

    protected static ?string $model = Client::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationGroup = 'Directory';

    public static function moduleKey(): string
    {
        return 'clients';
    }

    /**
     * @return class-string<Exporter>
     */
    public static function exporter(): ?string
    {
        return ClientExporter::class;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'view' => Pages\ViewClient::route('/{record}'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
