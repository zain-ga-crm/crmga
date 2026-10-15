<?php

namespace App\Filament\Platform\Resources\TenantResource\Pages;

use App\Filament\Platform\Resources\TenantResource;
use App\Models\Tenant;
use App\Support\Studio\StudioGovernance;
use App\Support\Tenancy\TenantStats;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTenant extends ViewRecord
{
    protected static string $resource = TenantResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        /** @var Tenant $tenant */
        $tenant = $infolist->getRecord();
        $stats = app(TenantStats::class)->forTenant($tenant);

        return $infolist->schema([
            TextEntry::make('id')->label('ID'),
            TextEntry::make('domain')->label('Domain')->state(TenantResource::domainOf($tenant)),
            TextEntry::make('status')->label('Status')->state(TenantResource::statusOf($tenant))->badge()
                ->color(fn (string $state): string => $state === 'suspended' ? 'danger' : 'success'),
            TextEntry::make('studio_mode')->label('Studio mode')->state(StudioGovernance::modeOf($tenant))->badge()
                ->color(fn (string $state): string => match ($state) {
                    StudioGovernance::MODE_DISABLED => 'danger',
                    StudioGovernance::MODE_REQUEST_ONLY => 'warning',
                    default => 'success',
                }),
            TextEntry::make('users')->label('Users')->state($stats['users']),
            TextEntry::make('records')->label('Records')->state($stats['records']),
            TextEntry::make('database_size_mb')->label('Database size (MB)')->state($stats['database_size_mb']),
            TextEntry::make('created_at')->label('Created')->dateTime(),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->getRecord();
        $suspended = TenantResource::statusOf($tenant) === 'suspended';

        return [
            Action::make('suspend')
                ->label('Suspend')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(! $suspended)
                ->requiresConfirmation()
                ->action(function () use ($tenant): void {
                    $tenant->update(['status' => 'suspended']);
                    Notification::make()->title('Company suspended')->success()->send();
                }),
            Action::make('resume')
                ->label('Resume')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible($suspended)
                ->action(function () use ($tenant): void {
                    $tenant->update(['status' => 'active']);
                    Notification::make()->title('Company resumed')->success()->send();
                }),
            Action::make('studioMode')
                ->label('Change Studio mode')
                ->icon('heroicon-o-adjustments-horizontal')
                ->color('gray')
                ->fillForm(['studio_mode' => StudioGovernance::modeOf($tenant)])
                ->form([
                    Select::make('studio_mode')
                        ->label('Studio mode')
                        ->options([
                            StudioGovernance::MODE_SELF_SERVICE => 'Self-service -- changes apply immediately',
                            StudioGovernance::MODE_REQUEST_ONLY => 'Request-only -- changes queue for approval',
                            StudioGovernance::MODE_DISABLED => 'Disabled -- Studio is hidden entirely',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) use ($tenant): void {
                    $mode = is_string($data['studio_mode'] ?? null) ? $data['studio_mode'] : StudioGovernance::MODE_SELF_SERVICE;
                    $tenant->update(['studio_mode' => $mode]);
                    Notification::make()->title('Studio mode updated')->success()->send();
                }),
        ];
    }
}
