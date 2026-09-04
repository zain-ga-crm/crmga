<?php

namespace App\Support\Filament;

use App\Models\Metadata\Module;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Support\FieldTypeContract;
use Filament\Forms\Components\Component as FormComponent;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\Column as TableColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\DB;

/**
 * S-1.3: maps a compiled field-metadata array (MetadataRepository::compiled()'s
 * per-field shape) to a real Filament form component and table column, per the
 * frozen contract's `component`/`table_cell` strings (resources/contracts/field-types.json).
 * Never invent a type mapping here -- if a type needs different Filament behaviour,
 * the contract changes first (both lanes, per its own $comment).
 *
 * DDL/validation stay elsewhere (SchemaManager, ApiValidationRuleBuilder); this class
 * only builds UI.
 *
 * S-1.4: enum table cells pick up a colour from each option item's own `color`
 * column when set (any dropdown, not a fixed list); a handful of named boolean
 * fields get a distinct colored badge instead of the generic checkbox icon via
 * BadgeRegistry's fixed, named list.
 */
final class FieldTypeRegistry
{
    public function __construct(private readonly FieldTypeContract $contract) {}

    /**
     * @param  array<string, mixed>  $field  one entry from a compiled module's 'fields' map
     */
    public function formComponent(array $field): FormComponent
    {
        $name = $this->str($field['name'] ?? null);
        $type = $this->str($field['type'] ?? null, 'text');
        $required = (bool) ($field['required'] ?? false);

        $component = match ($type) {
            'textarea' => Textarea::make($name)->rows(4),
            'enum' => $this->selectFor($field, multiple: false),
            'multienum' => $this->selectFor($field, multiple: true),
            'bool' => Toggle::make($name),
            'int' => TextInput::make($name)->numeric(),
            'decimal', 'currency' => TextInput::make($name)
                ->numeric()
                ->step(10 ** -$this->scaleFor($field, $type)),
            'date' => DatePicker::make($name),
            'datetime' => DateTimePicker::make($name),
            'email' => TextInput::make($name)->email(),
            'phone' => TextInput::make($name)->tel(),
            'url' => TextInput::make($name)->url(),
            'relate' => $this->relateSelect($field),
            'file' => FileUpload::make($name),
            'image' => FileUpload::make($name)->image(),
            default => TextInput::make($name)->maxLength(
                $this->intOrNull($field['max_length'] ?? null) ?? $this->contract->lengthDefault($type)
            ),
        };

        $component = $component->label($this->labelFor($field, $name))->required($required);

        $help = $this->str($field['help'] ?? null);
        if ($help !== '') {
            $component = $component->helperText($help);
        }

        return $component;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    public function tableColumn(array $field): TableColumn
    {
        $name = $this->str($field['name'] ?? null);
        $type = $this->str($field['type'] ?? null, 'text');

        $column = match ($type) {
            'textarea' => TextColumn::make($name)->limit(60),
            'enum' => $this->enumBadgeColumn($name, $field['option_list_id'] ?? null),
            // Colouring a multienum's badge *list* per-value would need Filament to
            // colour each pill of an array state independently, which its color()
            // closure doesn't do (one colour per cell, not per list item) -- left at
            // the contract's default badge styling rather than a misleading single colour.
            'multienum' => BadgeColumn::make($name),
            'bool' => BadgeRegistry::hasBooleanBadge($name) ? BadgeRegistry::booleanBadgeColumn($name) : IconColumn::make($name)->boolean(),
            'int', 'decimal' => TextColumn::make($name)->alignRight(),
            'currency' => TextColumn::make($name)->money('usd')->alignRight(),
            'date' => TextColumn::make($name)->date(),
            'datetime' => TextColumn::make($name)->dateTime(),
            'email' => TextColumn::make($name)->copyable()->url(fn (mixed $state): ?string => is_string($state) ? "mailto:{$state}" : null),
            'phone' => TextColumn::make($name)->copyable()->url($this->phoneUrl(...)),
            'url' => TextColumn::make($name)->url(fn (mixed $state): ?string => is_string($state) ? $state : null),
            'image' => ImageColumn::make($name),
            default => TextColumn::make($name),
        };

        $column = $column->label($this->labelFor($field, $name))
            ->searchable($this->contract->filterable($type))
            ->sortable($this->contract->sortable($type));

        return $column;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function selectFor(array $field, bool $multiple): Select
    {
        $name = $this->str($field['name'] ?? null);
        $options = $this->optionValues($field['option_list_id'] ?? null);

        $select = Select::make($name)->options($options);

        if ($multiple) {
            $select = $select->multiple();
        }

        if (count($options) > 8) {
            $select = $select->searchable();
        }

        return $select;
    }

    /**
     * A do_not_call record (Contactable's own flag, present on most person-like
     * modules) never gets a click-to-call link -- the audit's own scope notes
     * flag click-to-call as cut telephony scope; a plain tel: href isn't
     * telephony integration (no dialing infrastructure, nothing server-side),
     * but sitting right next to the DNC flag it's a real compliance risk this
     * CRM exists partly to prevent, so it's suppressed there regardless.
     */
    private function phoneUrl(mixed $state, mixed $record): ?string
    {
        if (! is_string($state) || $state === '') {
            return null;
        }

        if (is_object($record) && method_exists($record, 'getAttribute') && $record->getAttribute('do_not_call')) {
            return null;
        }

        return 'tel:'.preg_replace('/[^\d+]/', '', $state);
    }

    private function enumBadgeColumn(string $name, mixed $optionListId): BadgeColumn
    {
        $column = BadgeColumn::make($name);
        $colors = $this->optionColors($optionListId);

        if ($colors === []) {
            return $column;
        }

        return $column->color(fn (mixed $state): ?string => is_string($state) ? ($colors[$state] ?? null) : null);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function relateSelect(array $field): Select
    {
        $name = $this->str($field['name'] ?? null);
        $displayField = $this->str($field['related_display_field'] ?? null, 'id');
        $relatedModuleId = $field['related_module_id'] ?? null;
        $table = is_string($relatedModuleId) ? $this->relatedTable($relatedModuleId) : null;

        $select = Select::make($name)->searchable();

        if ($table === null) {
            return $select;
        }

        return $select
            ->getSearchResultsUsing(function (string $search) use ($table, $displayField): array {
                return DB::table($table)
                    ->where($displayField, 'like', "%{$search}%")
                    ->limit(50)
                    ->pluck($displayField, 'id')
                    ->all();
            })
            ->getOptionLabelUsing(function (mixed $value) use ($table, $displayField): ?string {
                if (! is_string($value)) {
                    return null;
                }

                $label = DB::table($table)->where('id', $value)->value($displayField);

                return is_string($label) ? $label : null;
            });
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function scaleFor(array $field, string $type): int
    {
        return $this->intOrNull($field['scale'] ?? null) ?? $this->contract->scaleDefault($type);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function labelFor(array $field, string $name): string
    {
        $label = $this->str($field['label'] ?? null);

        return $label !== '' ? $label : ucfirst(str_replace('_', ' ', $name));
    }

    /**
     * @return array<string, string>
     */
    private function optionValues(mixed $optionListId): array
    {
        if (! is_string($optionListId)) {
            return [];
        }

        $list = OptionList::query()->with('items')->find($optionListId);
        if ($list === null) {
            return [];
        }

        return $list->items
            ->mapWithKeys(fn (OptionItem $item): array => [$item->value => $item->label])
            ->all();
    }

    /**
     * @return array<string, string> option value => Filament colour name, items with no colour set omitted
     */
    private function optionColors(mixed $optionListId): array
    {
        if (! is_string($optionListId)) {
            return [];
        }

        $list = OptionList::query()->with('items')->find($optionListId);
        if ($list === null) {
            return [];
        }

        $colors = [];
        foreach ($list->items as $item) {
            if (is_string($item->color) && $item->color !== '') {
                $colors[$item->value] = $item->color;
            }
        }

        return $colors;
    }

    private function relatedTable(string $relatedModuleId): ?string
    {
        $module = Module::query()->find($relatedModuleId);

        return $module?->table_name;
    }

    private function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
