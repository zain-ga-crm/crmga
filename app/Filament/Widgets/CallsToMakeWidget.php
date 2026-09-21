<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\LeadResource;
use App\Models\Lead;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use Filament\Facades\Filament;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Calls to make" = a lead's follow-up falls today, same definition
 * DashboardService::callsToMake() uses for Z-4.3 -- queried directly here
 * (rather than reusing that array) so the table gets live pagination/sorting
 * for free from Filament's own table machinery, still scoped by the same
 * AppliesRecordAccess global scope on Lead::query().
 */
class CallsToMakeWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null
            && ($user->isAdmin() || app(Acl::class)->effective($user, 'leads', 'list') !== AccessLevel::None);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Calls to make today')
            ->query(fn (): Builder => Lead::query()
                ->whereNotNull('next_follow_up_at')
                ->whereDate('next_follow_up_at', now()->toDateString())
                ->orderBy('next_follow_up_at'))
            ->columns([
                TextColumn::make('full_name')->label('Name')->getStateUsing(fn (Lead $record): string => $record->fullName()),
                TextColumn::make('phone_mobile')->label('Phone'),
                TextColumn::make('next_follow_up_at')->label('Due')->dateTime(),
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->url(fn (Model $record): string => LeadResource::getUrl('view', ['record' => $record])),
            ])
            ->paginated([5, 10, 25])
            ->emptyStateHeading('No calls due today');
    }
}
