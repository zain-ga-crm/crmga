<?php

namespace App\Filament\Resources\Concerns;

use App\Models\SavedView;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * S-2.4: the one piece of the list-page framework description (saved views,
 * column chooser, filter panel, bulk-action bar, export) that didn't already
 * exist. Built once here and reused by every ListRecords page that adopts
 * it, same "built once, reused by every module" principle as
 * BuildsResourceFromMetadata itself -- a view is personal to the signed-in
 * user, scoped by module key, never shared.
 *
 * Reads/writes Filament's own public table-state properties
 * ($tableFilters/$tableSearch/$tableSortColumn/$tableSortDirection,
 * Filament\Tables\Concerns\InteractsWithTable) directly, since this trait is
 * only ever used on a page that already has them.
 */
trait HasSavedViews
{
    /**
     * The using page's own module key -- a one-line concrete call to that
     * resource's own moduleKey(), not a generic lookup across an arbitrary
     * Resource class-string (which PHPStan can't verify has the method).
     */
    abstract protected function savedViewModuleKey(): string;

    /**
     * @return array<Action>
     */
    protected function savedViewsActions(): array
    {
        return [
            $this->saveViewAction(),
            $this->applyViewAction(),
            $this->deleteViewAction(),
        ];
    }

    private function saveViewAction(): Action
    {
        return Action::make('saveView')
            ->label('Save view')
            ->icon('heroicon-o-bookmark')
            ->form([
                TextInput::make('name')
                    ->label('View name')
                    ->required()
                    ->maxLength(100),
            ])
            ->action(function (array $data): void {
                $name = is_string($data['name'] ?? null) ? $data['name'] : '';

                SavedView::query()->updateOrCreate(
                    ['user_id' => Auth::id(), 'module_key' => $this->savedViewModuleKey(), 'name' => $name],
                    [
                        'filters' => $this->tableFilters,
                        'search' => $this->tableSearch,
                        'sort_column' => $this->tableSortColumn,
                        'sort_direction' => $this->tableSortDirection,
                    ],
                );

                Notification::make()->title('View saved')->success()->send();
            });
    }

    private function applyViewAction(): Action
    {
        return Action::make('applyView')
            ->label('Saved views')
            ->icon('heroicon-o-bookmark-square')
            ->visible(fn (): bool => $this->savedViewsQuery()->exists())
            ->form([
                Select::make('saved_view_id')
                    ->label('View')
                    ->options(fn (): array => $this->savedViewsQuery()->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $id = $data['saved_view_id'] ?? null;
                $view = is_string($id) ? $this->savedViewsQuery()->find($id) : null;
                if ($view === null) {
                    return;
                }

                $this->tableFilters = $view->filters ?? [];
                $this->tableSearch = $view->search ?? '';
                $this->tableSortColumn = $view->sort_column;
                $this->tableSortDirection = $view->sort_direction;
                $this->resetPage();
            });
    }

    private function deleteViewAction(): Action
    {
        return Action::make('deleteView')
            ->label('Delete a saved view')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (): bool => $this->savedViewsQuery()->exists())
            ->requiresConfirmation()
            ->form([
                Select::make('saved_view_id')
                    ->label('View')
                    ->options(fn (): array => $this->savedViewsQuery()->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->action(function (array $data): void {
                $id = $data['saved_view_id'] ?? null;
                if (is_string($id)) {
                    $this->savedViewsQuery()->whereKey($id)->delete();
                    Notification::make()->title('View deleted')->success()->send();
                }
            });
    }

    /**
     * @return Builder<SavedView>
     */
    private function savedViewsQuery(): Builder
    {
        return SavedView::query()
            ->where('user_id', Auth::id())
            ->where('module_key', $this->savedViewModuleKey());
    }
}
