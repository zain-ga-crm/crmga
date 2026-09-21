<?php

namespace App\Filament\Pages;

use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Models\User;
use App\Support\MetadataValidationException;
use App\Support\OptionListManager;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * S-3.2: create/rename option lists; add, rename, reorder and remove their items; edit
 * an item's stored value and displayed label independently; used-by warning before a
 * change that would orphan stored data. Every write goes through OptionListManager --
 * this page never touches the option_items/option_lists tables directly.
 *
 * The Filament color names below (not hex) are what FieldTypeRegistry's enum/multienum
 * columns pass straight into TextColumn::color()/TextEntry::color() -- see its
 * optionColors().
 */
class DropdownEditor extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    private const COLOR_OPTIONS = [
        'danger' => 'Danger (red)',
        'warning' => 'Warning (amber)',
        'success' => 'Success (green)',
        'info' => 'Info (blue)',
        'primary' => 'Primary',
        'gray' => 'Gray',
    ];

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Studio';

    protected static ?string $slug = 'studio/dropdowns';

    protected static string $view = 'filament.pages.dropdown-editor';

    public ?string $listKey = null;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'Dropdown Editor';
    }

    public function mount(): void
    {
        $this->listKey = array_key_first($this->listOptions());
    }

    /**
     * @return array<string, string>
     */
    public function listOptions(): array
    {
        $options = [];
        foreach (OptionList::query()->orderBy('label')->get(['key', 'label']) as $list) {
            $options[(string) $list->key] = (string) $list->label;
        }

        return $options;
    }

    protected function currentList(): ?OptionList
    {
        if ($this->listKey === null) {
            return null;
        }

        return OptionList::query()->where('key', $this->listKey)->first();
    }

    private function currentUserId(): ?string
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $user?->id;
    }

    public function table(Table $table): Table
    {
        // currentList() is resolved lazily, inside the closure -- table() runs during
        // Livewire's boot() phase, before this request's property sync applies a new
        // listKey from the list select (see FieldManager's own table() for the same
        // fix and why capturing it eagerly here would bind to the PREVIOUS list).
        return $table
            ->query(fn (): Builder => ($list = $this->currentList()) !== null
                ? OptionItem::query()->where('option_list_id', $list->id)->orderBy('sort_order')
                : OptionItem::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('value')->searchable()->sortable(),
                TextColumn::make('label')->searchable(),
                TextColumn::make('color')->badge()->color(fn (?string $state): ?string => $state),
                IconColumn::make('is_active')->boolean(),
            ])
            ->reorderable('sort_order')
            ->headerActions([
                Action::make('createList')
                    ->label('New list')
                    ->icon('heroicon-o-plus-circle')
                    ->color('gray')
                    ->form([
                        TextInput::make('key')
                            ->required()
                            ->helperText('Lowercase, starts with a letter. Cannot be changed once created.'),
                        TextInput::make('label')->required(),
                    ])
                    ->action(function (array $data): void {
                        try {
                            $list = app(OptionListManager::class)->createList(
                                $this->stringValue($data, 'key'),
                                $this->stringValue($data, 'label'),
                                actorId: $this->currentUserId(),
                            );
                            $this->listKey = $list->key;
                            Notification::make()->title('List created')->success()->send();
                        } catch (MetadataValidationException $e) {
                            Notification::make()->title('Could not create list')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('renameList')
                    ->label('Rename list')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->visible(fn (): bool => $this->currentList()?->is_system === false)
                    ->fillForm(fn (): array => ['label' => $this->currentList()?->label])
                    ->form([TextInput::make('label')->required()])
                    ->action(function (array $data): void {
                        $list = $this->currentList();
                        if ($list === null) {
                            return;
                        }

                        try {
                            app(OptionListManager::class)->renameList($list->key, $this->stringValue($data, 'label'), actorId: $this->currentUserId());
                            Notification::make()->title('List renamed')->success()->send();
                        } catch (MetadataValidationException $e) {
                            Notification::make()->title('Could not rename list')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('create')
                    ->label('Add item')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->currentList()?->is_system === false)
                    ->form(self::itemFormSchema())
                    ->action(function (array $data): void {
                        $this->submitItem(null, $data);
                    }),
            ])
            ->actions([
                Action::make('edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => $this->currentList()?->is_system === false)
                    ->fillForm(fn (OptionItem $record): array => [
                        'value' => $record->value,
                        'label' => $record->label,
                        'color' => $record->color,
                        'is_active' => $record->is_active,
                        'confirm_lossy' => false,
                    ])
                    ->form(self::itemFormSchema(editing: true))
                    ->action(function (OptionItem $record, array $data): void {
                        $this->submitItem($record->value, $data);
                    }),
                Action::make('delete')
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->visible(fn (): bool => $this->currentList()?->is_system === false)
                    ->requiresConfirmation()
                    ->modalHeading(fn (OptionItem $record): string => "Delete option [{$record->value}]?")
                    ->modalDescription(fn (OptionItem $record): string => $this->deleteImpactDescription($record))
                    ->modalSubmitActionLabel('Delete')
                    ->action(function (OptionItem $record): void {
                        $list = $this->currentList();
                        if ($list === null) {
                            return;
                        }

                        try {
                            app(OptionListManager::class)->removeItem($list->key, $record->value, confirmLossy: true, actorId: $this->currentUserId());
                            Notification::make()->title('Option deleted')->success()->send();
                        } catch (MetadataValidationException $e) {
                            Notification::make()->title('Could not delete option')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('No options on this list yet');
    }

    /**
     * @param  array<int | string>  $order  OptionItem ids, in the new display order
     */
    public function reorderTable(array $order): void
    {
        $list = $this->currentList();
        if ($list === null) {
            return;
        }

        $valuesById = OptionItem::query()->where('option_list_id', $list->id)->pluck('value', 'id');
        $orderedValues = [];
        foreach ($order as $id) {
            $value = $valuesById->get($id);
            if (is_string($value)) {
                $orderedValues[] = $value;
            }
        }

        try {
            app(OptionListManager::class)->reorderItems($list->key, $orderedValues, actorId: $this->currentUserId());
        } catch (MetadataValidationException $e) {
            Notification::make()->title('Could not reorder')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function submitItem(?string $existingValue = null, array $data = []): void
    {
        $list = $this->currentList();
        if ($list === null) {
            return;
        }

        $manager = app(OptionListManager::class);
        $newValue = $this->stringValue($data, 'value');
        $label = $this->stringValue($data, 'label');
        $color = isset($data['color']) && is_string($data['color']) && $data['color'] !== '' ? $data['color'] : null;
        $isActive = (bool) ($data['is_active'] ?? true);
        $confirmLossy = (bool) ($data['confirm_lossy'] ?? false);

        try {
            if ($existingValue === null) {
                $manager->addItem($list->key, $newValue, $label, color: $color, isActive: $isActive, actorId: $this->currentUserId());
                Notification::make()->title('Option added')->success()->send();

                return;
            }

            $manager->updateItem(
                $list->key,
                $existingValue,
                newValue: $newValue,
                newLabel: $label,
                newColor: $color,
                newIsActive: $isActive,
                confirmLossy: $confirmLossy,
                actorId: $this->currentUserId(),
            );
            Notification::make()->title('Option updated')->success()->send();
        } catch (MetadataValidationException $e) {
            Notification::make()->title('Schema change rejected')->body($e->getMessage())->danger()->send();
        }
    }

    private function deleteImpactDescription(OptionItem $record): string
    {
        $list = $this->currentList();
        if ($list === null) {
            return 'This cannot be undone.';
        }

        $tables = app(OptionListManager::class)->usage($list->key, $record->value);

        if ($tables === []) {
            return 'Nothing currently references this option. This cannot be undone.';
        }

        return 'This option is in use on: '.implode(', ', $tables).
            '. Deleting it will not update those existing rows. This cannot be undone.';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return array<int, Component>
     */
    private static function itemFormSchema(bool $editing = false): array
    {
        return [
            TextInput::make('value')
                ->required()
                ->helperText($editing
                    ? 'Changing the stored value on an in-use option requires confirming a lossy change below.'
                    : 'The value stored on records. Cannot be blank.'),
            TextInput::make('label')->required(),
            Select::make('color')->options(self::COLOR_OPTIONS)->native(false),
            Toggle::make('is_active')->default(true),
            Toggle::make('confirm_lossy')
                ->label('I understand this change may affect existing records')
                ->visible($editing)
                ->default(false),
        ];
    }
}
