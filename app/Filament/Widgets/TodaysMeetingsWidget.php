<?php

namespace App\Filament\Widgets;

use App\Models\Meeting;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Today's meetings": meetings assigned to the signed-in user starting
 * today. Same reasoning as MyTasksWidget -- Meeting has no module-level ACL
 * of its own, so scoping to assigned_user_id is the access rule.
 */
class TodaysMeetingsWidget extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading("Today's meetings")
            ->query(fn (): Builder => Meeting::query()
                ->where('assigned_user_id', Filament::auth()->id())
                ->whereDate('date_start', now()->toDateString())
                ->orderBy('date_start'))
            ->columns([
                TextColumn::make('name')->label('Meeting'),
                TextColumn::make('location')->label('Location')->placeholder('—'),
                TextColumn::make('date_start')->label('Starts')->time(),
            ])
            ->paginated([5, 10, 25])
            ->emptyStateHeading('No meetings today');
    }
}
