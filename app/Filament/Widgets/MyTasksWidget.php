<?php

namespace App\Filament\Widgets;

use App\Models\Task;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * "My tasks": open (not completed) tasks assigned to the signed-in user,
 * soonest due first. Scoped to the current user only -- Task has no
 * module-level ACL of its own (it isn't a Contactable/HasAcl record), so
 * "assigned to me" is the whole access rule here.
 */
class MyTasksWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading('My tasks')
            ->query(fn (): Builder => Task::query()
                ->where('assigned_user_id', Filament::auth()->id())
                ->where('status', '!=', 'completed')
                ->orderByRaw('due_date IS NULL, due_date')) // NULL-due tasks last
            ->columns([
                TextColumn::make('name')->label('Task'),
                TextColumn::make('priority')->label('Priority')->badge(),
                TextColumn::make('due_date')->label('Due')->date(),
            ])
            ->paginated([5, 10, 25])
            ->emptyStateHeading('No open tasks');
    }
}
