<?php

namespace App\Filament\Pages;

use App\Models\Metadata\Field;
use App\Models\Metadata\Module;
use App\Models\Metadata\OptionList;
use App\Models\User;
use App\Support\FieldTypeContract;
use App\Support\SchemaManager\FieldChangeRequest;
use App\Support\SchemaManager\SchemaManager;
use App\Support\SchemaManager\SchemaValidationException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * S-3.1: list every field on a module, add/edit fields of any type with their
 * per-type options and behaviour flags, delete with an impact warning. Every
 * write goes through SchemaManager -- this page never touches the `fields`
 * table directly, so DDL, the change log and rollback stay the single source
 * of truth Z-3.1/Z-3.3 already built.
 */
class FieldManager extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationGroup = 'Studio';

    protected static ?string $slug = 'studio/fields';

    protected static string $view = 'filament.pages.field-manager';

    public ?string $moduleKey = null;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'Field Manager';
    }

    public function mount(): void
    {
        $this->moduleKey = array_key_first($this->moduleOptions());
    }

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        $options = [];
        foreach (Module::query()->where('is_system', false)->orderBy('label')->get(['key', 'label']) as $module) {
            $options[(string) $module->key] = (string) $module->label;
        }

        return $options;
    }

    private function currentUserId(): ?string
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $user?->id;
    }

    /**
     * SchemaManager's modify/delete DDL only ever targets a module's {table}_custom
     * sidecar (SchemaManager::planModify()) -- a field with is_custom=false lives on
     * the primary table itself (e.g. the base Contactable columns), so MODIFY COLUMN
     * against the sidecar fails with "column not found". is_system fields are already
     * blocked by SchemaManager::validate(); this additionally hides edit/delete for
     * any other non-custom field, since Studio has no DDL path for it today.
     */
    private function isFieldManageable(Field $record): bool
    {
        return $record->is_custom && ! $record->is_system;
    }

    protected function currentModule(): ?Module
    {
        if ($this->moduleKey === null) {
            return null;
        }

        return Module::query()->where('key', $this->moduleKey)->first();
    }

    public function table(Table $table): Table
    {
        // currentModule() is resolved lazily, inside the closure, rather than once up
        // here -- table() runs during Livewire's boot() phase, which happens BEFORE
        // this request's property sync applies a new moduleKey from the module select.
        // Capturing $this->currentModule() eagerly would bind the query to whichever
        // module was selected on the PREVIOUS request.
        return $table
            ->query(fn (): Builder => ($module = $this->currentModule()) !== null
                ? Field::query()->where('module_id', $module->id)->orderBy('sort_order')->orderBy('name')
                : Field::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('label')->searchable(),
                TextColumn::make('type')->badge(),
                IconColumn::make('required')->boolean(),
                IconColumn::make('filterable')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('sortable')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('audited')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('mass_update')->label('Mass update')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('duplicate_merge')->label('Duplicate merge')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('reportable')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('importable')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_system')->label('System')->boolean(),
            ])
            ->headerActions([
                Action::make('create')
                    ->label('Add field')
                    ->icon('heroicon-o-plus')
                    ->modalWidth('lg')
                    ->form(self::fieldFormSchema(editing: false))
                    ->action(function (array $data): void {
                        $this->submitFieldChange('add', $this->stringValue($data, 'name'), $data);
                    }),
            ])
            ->actions([
                Action::make('edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (Field $record): bool => $this->isFieldManageable($record))
                    ->modalWidth('lg')
                    ->fillForm(fn (Field $record): array => [
                        'name' => $record->name,
                        'label' => $record->label,
                        'type' => $record->type,
                        'required' => $record->required,
                        'default_value' => $record->default_value,
                        'help' => $record->help,
                        'length' => $record->max_length,
                        'precision' => $record->precision,
                        'scale' => $record->scale,
                        'option_list_id' => $record->option_list_id,
                        'related_module_id' => $record->related_module_id,
                        'related_display_field' => $record->related_display_field,
                        'audited' => $record->audited,
                        'mass_update' => $record->mass_update,
                        'duplicate_merge' => $record->duplicate_merge,
                        'reportable' => $record->reportable,
                        'importable' => $record->importable,
                        'confirm_lossy' => false,
                    ])
                    ->form(self::fieldFormSchema(editing: true))
                    ->action(function (Field $record, array $data): void {
                        $this->submitFieldChange('modify', (string) $record->name, $data);
                    }),
                Action::make('delete')
                    ->color('danger')
                    ->icon('heroicon-o-trash')
                    ->visible(fn (Field $record): bool => $this->isFieldManageable($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Field $record): string => "Delete field [{$record->name}]?")
                    ->modalDescription(fn (Field $record): string => $this->deleteImpactDescription($record))
                    ->modalSubmitActionLabel('Delete')
                    ->action(function (Field $record): void {
                        $this->submitFieldChange('delete', (string) $record->name, [], confirmLossy: true);
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('No fields on this module yet');
    }

    /**
     * @param  'add'|'modify'|'delete'  $action
     * @param  array<array-key, mixed>  $data
     */
    private function submitFieldChange(string $action, string $fieldName, array $data, bool $confirmLossy = false): void
    {
        $module = $this->currentModule();
        if ($module === null) {
            return;
        }

        $type = isset($data['type']) && is_string($data['type']) ? $data['type'] : null;
        $confirmLossy = $confirmLossy || (bool) ($data['confirm_lossy'] ?? false);

        try {
            $manager = app(SchemaManager::class);
            $plan = $manager->plan(new FieldChangeRequest(
                $action,
                $module->key,
                $fieldName,
                $type,
                self::optionsFromFormData($data),
                $confirmLossy,
            ));
            $result = $manager->apply($plan, actorId: $this->currentUserId());

            if (! $result->success) {
                Notification::make()->title('Schema change failed')->body($result->error)->danger()->send();

                return;
            }

            Notification::make()->title(match ($action) {
                'add' => 'Field added',
                'modify' => 'Field updated',
                default => 'Field deleted',
            })->success()->send();
        } catch (SchemaValidationException $e) {
            Notification::make()->title('Schema change rejected')->body($e->getMessage())->danger()->send();
        }
    }

    private function deleteImpactDescription(Field $record): string
    {
        $module = $this->currentModule();
        if ($module === null) {
            return 'This cannot be undone.';
        }

        $impact = app(SchemaManager::class)->impact($module->key, $record->name);

        $lines = [];
        foreach ($impact['layouts'] as $layout) {
            $lines[] = "layout {$layout['view']} v{$layout['version']}".($layout['is_published'] ? ' (published)' : ' (draft)');
        }
        foreach ($impact['roles'] as $roleName) {
            $lines[] = "role \"{$roleName}\"";
        }

        if ($lines === []) {
            return 'Nothing currently references this field. This cannot be undone.';
        }

        return 'This field is referenced by: '.implode(', ', $lines).
            '. Deleting it will not remove those references. This cannot be undone.';
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
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function optionsFromFormData(array $data): array
    {
        return [
            'label' => $data['label'] ?? null,
            'required' => (bool) ($data['required'] ?? false),
            'default' => $data['default_value'] ?? null,
            'help' => $data['help'] ?? null,
            'length' => $data['length'] ?? null,
            'precision' => $data['precision'] ?? null,
            'scale' => $data['scale'] ?? null,
            'option_list_id' => $data['option_list_id'] ?? null,
            'related_module_id' => $data['related_module_id'] ?? null,
            'related_display_field' => $data['related_display_field'] ?? null,
            'audited' => (bool) ($data['audited'] ?? false),
            'mass_update' => (bool) ($data['mass_update'] ?? false),
            'duplicate_merge' => (bool) ($data['duplicate_merge'] ?? false),
            'reportable' => (bool) ($data['reportable'] ?? true),
            'importable' => (bool) ($data['importable'] ?? true),
        ];
    }

    /**
     * Shared by the "Add field" header action and the "edit" row action --
     * only the name field's editability and the confirm_lossy toggle differ.
     *
     * @return array<int, Component>
     */
    private static function fieldFormSchema(bool $editing): array
    {
        $contract = app(FieldTypeContract::class);
        $typeOptions = collect($contract->types())
            ->mapWithKeys(fn (string $type): array => [$type => Str::of($type)->headline()->toString()])
            ->all();

        return [
            TextInput::make('name')
                ->required()
                ->disabled($editing)
                ->rule('regex:/'.$contract->fieldNamePattern().'/')
                ->helperText('Lowercase, starts with a letter. Cannot be changed once created.'),
            TextInput::make('label')->required(),
            Select::make('type')
                ->options($typeOptions)
                ->required()
                ->live()
                ->helperText($editing ? 'Changing the type may require confirming a lossy change below.' : null),
            TextInput::make('length')
                ->numeric()
                ->visible(fn (Get $get): bool => $get('type') === 'text')
                ->helperText(fn () => 'Between '.$contract->lengthMin('text').' and '.$contract->lengthMax('text').'.'),
            TextInput::make('precision')
                ->numeric()
                ->visible(fn (Get $get): bool => $get('type') === 'decimal'),
            TextInput::make('scale')
                ->numeric()
                ->visible(fn (Get $get): bool => $get('type') === 'decimal'),
            Select::make('option_list_id')
                ->label('Option list')
                ->options(fn (): array => OptionList::query()->orderBy('label')->pluck('label', 'id')->all())
                ->required(fn (Get $get): bool => in_array($get('type'), ['enum', 'multienum'], true))
                ->visible(fn (Get $get): bool => in_array($get('type'), ['enum', 'multienum'], true)),
            Select::make('related_module_id')
                ->label('Related module')
                ->options(fn (): array => Module::query()->where('is_system', false)->orderBy('label')->pluck('label', 'id')->all())
                ->required(fn (Get $get): bool => $get('type') === 'relate')
                ->visible(fn (Get $get): bool => $get('type') === 'relate')
                ->live(),
            Select::make('related_display_field')
                ->label('Display field')
                ->options(fn (Get $get): array => $get('related_module_id')
                    ? Field::query()->where('module_id', $get('related_module_id'))->orderBy('name')->pluck('label', 'name')->all()
                    : [])
                ->required(fn (Get $get): bool => $get('type') === 'relate')
                ->visible(fn (Get $get): bool => $get('type') === 'relate'),
            Toggle::make('required')->default(false),
            TextInput::make('default_value')->label('Default value'),
            Textarea::make('help')->label('Help text')->rows(2),
            Toggle::make('audited')->helperText('Log every change to this field in the audit trail.')->default(false),
            Toggle::make('mass_update')->label('Mass update')->helperText('Allow bulk-editing this field.')->default(false),
            Toggle::make('duplicate_merge')->label('Duplicate merge')->helperText('Consider this field when merging duplicate records.')->default(false),
            Toggle::make('reportable')->default(true),
            Toggle::make('importable')->default(true),
            Toggle::make('confirm_lossy')
                ->label('I understand this change may lose data')
                ->visible($editing)
                ->default(false),
        ];
    }
}
