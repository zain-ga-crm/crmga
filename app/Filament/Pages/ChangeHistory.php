<?php

namespace App\Filament\Pages;

use App\Models\Metadata\Change;
use App\Models\User;
use App\Support\Filament\ChangeDescriber;
use App\Support\SchemaManager\ConcurrentSchemaChange;
use App\Support\SchemaManager\SchemaManager;
use App\Support\SchemaManager\SnapshotFailed;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * S-3.4: who changed what and when, in plain language (ChangeDescriber), with a
 * rollback action. Rollback is only ever offered for the kinds SchemaManager::
 * rollback() actually knows how to reverse -- the field.* kinds, which carry a
 * target_module/target_field pair and (for add/modify) a schema snapshot. Every
 * other kind (layout.*, option*.*) is still fully visible here for audit, just
 * without a rollback button, matching what the backend genuinely supports today
 * rather than exposing an action that would only fail.
 */
class ChangeHistory extends Page implements HasTable
{
    use InteractsWithTable;

    private const ROLLBACKABLE_KINDS = ['field.add', 'field.modify', 'field.delete'];

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Studio';

    protected static ?string $slug = 'studio/changes';

    protected static string $view = 'filament.pages.change-history';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'Change history';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Change::query()->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('actor.name')->label('Who')->default('System'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('description')
                    ->label('Change')
                    ->getStateUsing(fn (Change $record): string => ChangeDescriber::describe($record))
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'applied' => 'success',
                        'rolled_back' => 'gray',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->searchable(false)
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('rollback')
                    ->color('danger')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (Change $record): bool => $this->isRollbackable($record))
                    ->requiresConfirmation()
                    ->modalHeading('Roll back this change?')
                    ->modalDescription(fn (Change $record): string => ChangeDescriber::describe($record).
                        ' Rolling back restores the schema (and any data it altered) to how it was immediately before this change.')
                    ->modalSubmitActionLabel('Roll back')
                    ->action(function (Change $record): void {
                        $this->rollback($record);
                    }),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No changes recorded yet');
    }

    private function isRollbackable(Change $record): bool
    {
        return in_array($record->kind, self::ROLLBACKABLE_KINDS, true)
            && $record->target_module !== null
            && $record->target_field !== null
            && $record->status === 'applied';
    }

    private function rollback(Change $record): void
    {
        $actorId = $this->currentUserId();
        if ($actorId === null) {
            return;
        }

        try {
            $result = app(SchemaManager::class)->rollback($record->id, $actorId);

            if (! $result->success) {
                Notification::make()->title('Rollback failed')->body($result->error)->danger()->send();

                return;
            }

            Notification::make()->title('Change rolled back')->success()->send();
        } catch (SnapshotFailed|ConcurrentSchemaChange $e) {
            Notification::make()->title('Rollback failed')->body($e->getMessage())->danger()->send();
        }
    }

    private function currentUserId(): ?string
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $user?->id;
    }
}
