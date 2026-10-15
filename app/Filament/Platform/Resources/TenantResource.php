<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\TenantResource\Pages;
use App\Models\Tenant;
use App\Support\Studio\StudioGovernance;
use App\Support\Tenancy\TenantStats;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S-8.1: companies list/detail/suspend/resume. Not a BuildsResourceFromMetadata
 * DynamicResource -- a Tenant isn't a CRM module, it's the thing that OWNS a
 * whole separate database of them, so its own fields are hand-built here.
 *
 * User/record counts and database size come from TenantStats, which has to
 * switch into each tenant's own database to read them (tenancy()->initialize()
 * / ->end()) -- real but relatively slow per row; acceptable for an
 * admin-only screen expected to list a handful of companies, not thousands.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Companies';

    public static function table(Table $table): Table
    {
        $stats = app(TenantStats::class);

        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->limit(12)->copyable(),
                TextColumn::make('domain')
                    ->label('Domain')
                    ->getStateUsing(fn (Tenant $record): string => self::domainOf($record)),
                TextColumn::make('status')
                    ->badge()
                    ->getStateUsing(fn (Tenant $record): string => self::statusOf($record))
                    ->color(fn (string $state): string => $state === 'suspended' ? 'danger' : 'success'),
                TextColumn::make('studio_mode')
                    ->label('Studio')
                    ->badge()
                    ->getStateUsing(fn (Tenant $record): string => StudioGovernance::modeOf($record))
                    ->color(fn (string $state): string => match ($state) {
                        StudioGovernance::MODE_DISABLED => 'danger',
                        StudioGovernance::MODE_REQUEST_ONLY => 'warning',
                        default => 'success',
                    }),
                TextColumn::make('users')
                    ->getStateUsing(fn (Tenant $record) => $stats->forTenant($record)['users']),
                TextColumn::make('records')
                    ->getStateUsing(fn (Tenant $record) => $stats->forTenant($record)['records']),
                TextColumn::make('database_size_mb')
                    ->label('DB size (MB)')
                    ->getStateUsing(fn (Tenant $record) => $stats->forTenant($record)['database_size_mb']),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Tenant $record): bool => self::statusOf($record) !== 'suspended')
                    ->requiresConfirmation()
                    ->action(fn (Tenant $record) => $record->update(['status' => 'suspended'])),
                Action::make('resume')
                    ->label('Resume')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Tenant $record): bool => self::statusOf($record) === 'suspended')
                    ->action(fn (Tenant $record) => $record->update(['status' => 'active'])),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'view' => Pages\ViewTenant::route('/{record}'),
        ];
    }

    public static function statusOf(Tenant $tenant): string
    {
        $status = $tenant->getAttribute('status');

        return is_string($status) && $status !== '' ? $status : 'active';
    }

    public static function domainOf(Tenant $tenant): string
    {
        $domain = $tenant->domains->first()?->getAttribute('domain');

        return is_string($domain) && $domain !== '' ? $domain : '—';
    }
}
