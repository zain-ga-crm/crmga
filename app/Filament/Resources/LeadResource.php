<?php

namespace App\Filament\Resources;

use App\Filament\Resources\Concerns\BuildsResourceFromMetadata;
use App\Filament\Resources\LeadResource\Pages;
use App\Models\Lead;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * S-2.1: the first real DynamicResource -- everything here (form, table,
 * infolist, filters, ACL) comes from BuildsResourceFromMetadata reading the
 * 'leads' module's own tenant_fields/tenant_layouts. Nothing module-specific
 * lives in this class beyond identifying which model/module it is.
 */
class LeadResource extends Resource
{
    use BuildsResourceFromMetadata;

    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static ?string $navigationGroup = 'Sales & Intake';

    public static function moduleKey(): string
    {
        return 'leads';
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'create' => Pages\CreateLead::route('/create'),
            'view' => Pages\ViewLead::route('/{record}'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }
}
