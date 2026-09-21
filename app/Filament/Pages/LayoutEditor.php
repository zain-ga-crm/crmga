<?php

namespace App\Filament\Pages;

use App\Models\Metadata\Field;
use App\Models\Metadata\Layout;
use App\Models\Metadata\Module;
use App\Models\User;
use App\Support\Filament\LayoutRowPacker;
use App\Support\LayoutManager;
use App\Support\MetadataValidationException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;

/**
 * S-3.3: choose which fields appear on a module's list/detail/edit/search view, their
 * order, which panel/tab they sit in (detail/edit) and column widths (list/search),
 * with a preview and full version history. Every save goes through LayoutManager,
 * which is append-only -- there is no "edit a draft in place"; every Save creates a new
 * version (LayoutManager::draft()'s own contract), and this page never writes the
 * `layouts` table directly.
 *
 * The panel/edit views' `rows` (pairs of up to two field slots, per the layout
 * contract) are edited here as one flat, reorderable list of slots instead -- see
 * LayoutRowPacker for why and how that's packed back into row-pairs on save.
 *
 * Unlike FieldManager/DropdownEditor's HasTable pages, this one only implements
 * HasForms. Filament's forms trait builds the form lazily on first access (there is no
 * `bootedInteractsWithForms()` boot-phase hook, unlike HasTable's `table()`), so
 * `moduleKey`/`viewName` are always current by the time form() runs -- no stale-closure
 * risk to work around here.
 *
 * @property Form $form
 */
class LayoutEditor extends Page implements HasForms
{
    use InteractsWithForms;

    private const VIEWS = ['list' => 'List', 'detail' => 'Detail', 'edit' => 'Edit', 'search' => 'Search'];

    private const OPERATORS = [
        'eq' => 'equals',
        'neq' => 'not equals',
        'in' => 'in',
        'not_in' => 'not in',
        'empty' => 'empty',
        'not_empty' => 'not empty',
    ];

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Studio';

    protected static ?string $slug = 'studio/layouts';

    protected static string $view = 'filament.pages.layout-editor';

    public ?string $moduleKey = null;

    public string $viewName = 'list';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'Layout Editor';
    }

    public function mount(): void
    {
        $this->moduleKey = array_key_first($this->moduleOptions());
        $this->loadIntoForm();
    }

    public function updatedModuleKey(): void
    {
        $this->loadIntoForm();
    }

    public function updatedViewName(): void
    {
        $this->loadIntoForm();
    }

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        $options = [];
        foreach (Module::query()->orderBy('label')->get(['key', 'label']) as $module) {
            $options[(string) $module->key] = (string) $module->label;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function viewOptions(): array
    {
        return self::VIEWS;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('saveDraft')
                ->label('Save as new draft')
                ->color('gray')
                ->action('saveDraft'),
            Action::make('saveAndPublish')
                ->label('Save & publish')
                ->color('success')
                ->action('saveAndPublish'),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema($this->isColumnsView() ? $this->columnsSchema() : $this->panelsSchema())
            ->statePath('data');
    }

    public function saveDraft(): void
    {
        $this->persistDraft();
    }

    public function saveAndPublish(): void
    {
        $layout = $this->persistDraft();
        if ($layout === null) {
            return;
        }

        try {
            app(LayoutManager::class)->publish($layout->id, actorId: $this->currentUserId());
            Notification::make()->title("Version {$layout->version} published")->success()->send();
        } catch (MetadataValidationException $e) {
            Notification::make()->title('Could not publish')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * @return Collection<int, Layout>
     */
    public function versions(): Collection
    {
        $module = $this->currentModule();
        if ($module === null) {
            return new Collection;
        }

        return Layout::query()
            ->where('module_id', $module->id)
            ->where('view', $this->viewName)
            ->orderByDesc('version')
            ->get();
    }

    public function revertToVersion(string $layoutId): void
    {
        $module = $this->currentModule();
        $layout = Layout::query()->find($layoutId);
        if ($module === null || $layout === null) {
            return;
        }

        try {
            app(LayoutManager::class)->revert($module->key, $this->viewName, $layout->version, actorId: $this->currentUserId());
            Notification::make()->title("Reverted to version {$layout->version} and published it")->success()->send();
            $this->loadIntoForm();
        } catch (MetadataValidationException $e) {
            Notification::make()->title('Could not revert')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * A schematic (not live-data) preview of the CURRENT, possibly-unsaved form state --
     * reads $this->data directly rather than $this->form->getState(), which would run
     * full validation and interrupt the preview while the admin is still mid-edit.
     *
     * @return list<array<string, mixed>>
     */
    public function previewRows(): array
    {
        if ($this->isColumnsView()) {
            $rows = [];
            foreach ($this->dataList('columns') as $column) {
                $field = is_string($column['field'] ?? null) ? $column['field'] : '';
                $rows[] = [
                    'field' => $field,
                    'label' => $this->displayLabel($column['label'] ?? null, $field),
                    'width' => $column['width'] ?? null,
                    'priority' => $column['priority'] ?? 1,
                ];
            }

            return $rows;
        }

        $rows = [];
        foreach ($this->dataList('panels') as $panel) {
            $slots = [];
            foreach ($this->dataListFrom($panel, 'slots') as $slot) {
                $field = is_string($slot['field'] ?? null) ? $slot['field'] : '';
                $slots[] = [
                    'field' => $field,
                    'label' => $this->displayLabel($slot['label'] ?? null, $field),
                    'span' => $slot['span'] ?? 'half',
                ];
            }

            $tab = is_string($panel['tab'] ?? null) ? $panel['tab'] : '';
            $rows[] = [
                'label' => is_string($panel['label'] ?? null) ? $panel['label'] : '',
                'tab' => $tab !== '' ? $tab : null,
                'rows' => LayoutRowPacker::pack($slots),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function dataList(string $key): array
    {
        return self::asList(is_array($this->data) ? ($this->data[$key] ?? null) : null);
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return list<array<array-key, mixed>>
     */
    private function dataListFrom(array $item, string $key): array
    {
        return self::asList($item[$key] ?? null);
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function asList(mixed $value): array
    {
        $list = [];
        if (! is_array($value)) {
            return $list;
        }

        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @param  array<array-key, mixed>  $item
     */
    private static function orderOf(array $item): int
    {
        return is_numeric($item['order'] ?? null) ? (int) $item['order'] : 0;
    }

    private function displayLabel(mixed $override, string $field): string
    {
        if (is_string($override) && $override !== '') {
            return $override;
        }

        return $this->fieldOptions()[$field] ?? $field;
    }

    public function isColumnsView(): bool
    {
        return in_array($this->viewName, ['list', 'search'], true);
    }

    protected function currentModule(): ?Module
    {
        if ($this->moduleKey === null) {
            return null;
        }

        return Module::query()->where('key', $this->moduleKey)->first();
    }

    private function currentUserId(): ?string
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $user?->id;
    }

    /**
     * @return array<string, string>
     */
    private function fieldOptions(): array
    {
        $module = $this->currentModule();
        if ($module === null) {
            return [];
        }

        $options = [];
        foreach (Field::query()->where('module_id', $module->id)->orderBy('name')->get(['name', 'label']) as $field) {
            $options[(string) $field->name] = is_string($field->label) && $field->label !== '' ? $field->label : (string) $field->name;
        }

        if ($this->isColumnsView()) {
            $options['flags'] = 'flags (virtual, list/search only)';
        }

        return $options;
    }

    private function loadIntoForm(): void
    {
        $module = $this->currentModule();
        $layout = $module === null ? null : Layout::query()
            ->where('module_id', $module->id)
            ->where('view', $this->viewName)
            ->orderByDesc('version')
            ->first();

        $this->data = $layout !== null ? $this->definitionToFormState($layout->definition) : $this->emptyFormState();
        $this->form->fill($this->data);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFormState(): array
    {
        return $this->isColumnsView()
            ? ['columns' => [], 'default_sort_enabled' => false, 'default_sort_field' => null, 'default_sort_direction' => 'desc']
            : ['tabs' => [], 'panels' => []];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function definitionToFormState(array $definition): array
    {
        $content = is_array($definition['content'] ?? null) ? $definition['content'] : [];

        if ($this->isColumnsView()) {
            $columns = [];
            foreach (self::asList($content['columns'] ?? null) as $c) {
                $columns[] = [
                    'field' => self::str($c['field'] ?? null, ''),
                    'label' => self::str($c['label'] ?? null),
                    'width' => is_numeric($c['width'] ?? null) ? (int) $c['width'] : null,
                    'priority' => is_numeric($c['priority'] ?? null) ? (int) $c['priority'] : 1,
                    'sortable' => (bool) ($c['sortable'] ?? true),
                    'align' => self::str($c['align'] ?? null, 'left'),
                    'wrap' => (bool) ($c['wrap'] ?? false),
                    'link' => (bool) ($c['link'] ?? false),
                ];
            }

            $defaultSort = is_array($content['default_sort'] ?? null) ? $content['default_sort'] : null;

            return [
                'columns' => $columns,
                'default_sort_enabled' => $defaultSort !== null,
                'default_sort_field' => $defaultSort['field'] ?? null,
                'default_sort_direction' => $defaultSort['direction'] ?? 'desc',
            ];
        }

        $tabs = [];
        foreach (self::asList($content['tabs'] ?? null) as $t) {
            $tabs[] = ['key' => self::str($t['key'] ?? null, ''), 'label' => self::str($t['label'] ?? null, '')];
        }

        $rawPanels = self::asList($content['panels'] ?? null);
        usort($rawPanels, fn (array $a, array $b): int => self::orderOf($a) <=> self::orderOf($b));

        $panels = [];
        foreach ($rawPanels as $p) {
            $rows = [];
            foreach (self::asList($p['rows'] ?? null) as $row) {
                $rows[] = self::asList($row);
            }

            $slots = [];
            foreach (LayoutRowPacker::unpack($rows) as $slot) {
                $slotState = [
                    'field' => self::str($slot['field'] ?? null, ''),
                    'label' => self::str($slot['label'] ?? null),
                    'span' => ($slot['span'] ?? 'half') === 'full' ? 'full' : 'half',
                    'readonly' => (bool) ($slot['readonly'] ?? false),
                ];

                if ($this->viewName === 'detail') {
                    $slotState['hide_when_empty'] = (bool) ($slot['hide_when_empty'] ?? true);
                } else {
                    $req = $slot['required_override'] ?? null;
                    $slotState['required_override'] = $req === true ? '1' : ($req === false ? '0' : '');
                }

                $slots[] = [...$slotState, ...self::visibleWhenToState($slot['visible_when'] ?? null)];
            }

            $panelState = [
                'key' => self::str($p['key'] ?? null, ''),
                'label' => self::str($p['label'] ?? null, ''),
                'tab' => self::str($p['tab'] ?? null, ''),
                'collapsed' => (bool) ($p['collapsed'] ?? false),
                'columns' => is_numeric($p['columns'] ?? null) ? (int) $p['columns'] : 2,
                'slots' => $slots,
            ];

            $panels[] = [...$panelState, ...self::visibleWhenToState($p['visible_when'] ?? null)];
        }

        return ['tabs' => $tabs, 'panels' => $panels];
    }

    /**
     * @return array{visible_when_enabled: bool, visible_when_field: ?string, visible_when_operator: ?string, visible_when_value: mixed}
     */
    private static function visibleWhenToState(mixed $vw): array
    {
        if (! is_array($vw)) {
            return ['visible_when_enabled' => false, 'visible_when_field' => null, 'visible_when_operator' => null, 'visible_when_value' => null];
        }

        return [
            'visible_when_enabled' => true,
            'visible_when_field' => self::str($vw['field'] ?? null, ''),
            'visible_when_operator' => self::str($vw['operator'] ?? null, 'eq'),
            'visible_when_value' => $vw['value'] ?? null,
        ];
    }

    private function persistDraft(): ?Layout
    {
        $module = $this->currentModule();
        if ($module === null) {
            return null;
        }

        $state = $this->form->getState();
        $definition = $this->buildDefinition($state, $module->key);

        try {
            $layout = app(LayoutManager::class)->draft($module->key, $this->viewName, $definition, actorId: $this->currentUserId());
            Notification::make()->title("Saved as version {$layout->version}")->success()->send();

            return $layout;
        } catch (MetadataValidationException $e) {
            Notification::make()->title('Could not save')->body($e->getMessage())->danger()->send();

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildDefinition(array $data, string $moduleKey): array
    {
        if ($this->isColumnsView()) {
            $columns = [];
            foreach (self::asList($data['columns'] ?? null) as $c) {
                $columns[] = self::cleanColumn($c);
            }

            $content = ['columns' => $columns];

            if ($data['default_sort_enabled'] ?? false) {
                $content['default_sort'] = [
                    'field' => self::str($data['default_sort_field'] ?? null, ''),
                    'direction' => $data['default_sort_direction'] ?? 'desc',
                ];
            }
        } else {
            $tabs = [];
            foreach (self::asList($data['tabs'] ?? null) as $i => $t) {
                $tabs[] = ['key' => self::str($t['key'] ?? null, ''), 'label' => self::str($t['label'] ?? null, ''), 'order' => $i];
            }

            $isDetail = $this->viewName === 'detail';
            $panels = [];
            foreach (self::asList($data['panels'] ?? null) as $i => $panel) {
                $slots = [];
                foreach (self::asList($panel['slots'] ?? null) as $s) {
                    $slots[] = self::cleanSlot($s, $isDetail);
                }

                $tab = self::str($panel['tab'] ?? null);
                $out = [
                    'key' => self::str($panel['key'] ?? null, ''),
                    'label' => self::str($panel['label'] ?? null, ''),
                    'tab' => $tab !== null && $tab !== '' ? $tab : null,
                    'order' => $i,
                    'collapsed' => (bool) ($panel['collapsed'] ?? false),
                    'columns' => is_numeric($panel['columns'] ?? null) ? (int) $panel['columns'] : 2,
                    'rows' => LayoutRowPacker::pack($slots),
                ];

                $visibleWhen = self::extractVisibleWhen($panel);
                if ($visibleWhen !== null) {
                    $out['visible_when'] = $visibleWhen;
                }

                $panels[] = $out;
            }

            $content = $tabs !== [] ? ['tabs' => $tabs, 'panels' => $panels] : ['panels' => $panels];
        }

        return ['version' => 1, 'view' => $this->viewName, 'module' => $moduleKey, 'content' => $content];
    }

    /**
     * @param  array<array-key, mixed>  $c
     * @return array<string, mixed>
     */
    private static function cleanColumn(array $c): array
    {
        $out = ['field' => self::str($c['field'] ?? null, '')];

        $label = self::str($c['label'] ?? null);
        if ($label !== null) {
            $out['label'] = $label;
        }
        if (is_numeric($c['width'] ?? null)) {
            $out['width'] = (int) $c['width'];
        }

        $out['priority'] = is_numeric($c['priority'] ?? null) ? (int) $c['priority'] : 1;
        $out['sortable'] = (bool) ($c['sortable'] ?? true);
        $out['align'] = self::str($c['align'] ?? null, 'left');
        $out['wrap'] = (bool) ($c['wrap'] ?? false);
        $out['link'] = (bool) ($c['link'] ?? false);

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $s
     * @return array<string, mixed>
     */
    private static function cleanSlot(array $s, bool $isDetail): array
    {
        $out = ['field' => self::str($s['field'] ?? null, '')];

        $label = self::str($s['label'] ?? null);
        if ($label !== null) {
            $out['label'] = $label;
        }

        $out['span'] = ($s['span'] ?? 'half') === 'full' ? 'full' : 'half';
        $out['readonly'] = (bool) ($s['readonly'] ?? false);

        if ($isDetail) {
            $out['hide_when_empty'] = (bool) ($s['hide_when_empty'] ?? true);
        } else {
            $raw = $s['required_override'] ?? '';
            $out['required_override'] = $raw === '1' ? true : ($raw === '0' ? false : null);
        }

        $visibleWhen = self::extractVisibleWhen($s);
        if ($visibleWhen !== null) {
            $out['visible_when'] = $visibleWhen;
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function extractVisibleWhen(array $item): ?array
    {
        if (! ($item['visible_when_enabled'] ?? false)) {
            return null;
        }

        $field = self::str($item['visible_when_field'] ?? null);
        $operator = self::str($item['visible_when_operator'] ?? null);
        if ($field === null || $field === '' || $operator === null) {
            return null;
        }

        return [
            'field' => $field,
            'operator' => $operator,
            'value' => in_array($operator, ['empty', 'not_empty'], true) ? null : ($item['visible_when_value'] ?? null),
        ];
    }

    private static function str(mixed $v, ?string $default = null): ?string
    {
        return is_string($v) ? $v : $default;
    }

    /**
     * @return array<int, Component>
     */
    private function columnsSchema(): array
    {
        $fieldOptions = $this->fieldOptions();
        $isList = $this->viewName === 'list';

        return [
            Repeater::make('columns')
                ->label('Columns')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('field')->options($fieldOptions)->searchable()->required(),
                        TextInput::make('label')->label('Label override')->helperText("Blank uses the field's own label."),
                    ]),
                    Grid::make(4)->schema([
                        TextInput::make('width')->label('Width (px)')->numeric(),
                        Select::make('priority')->options([
                            1 => '1 - always visible',
                            2 => '2 - hidden below md',
                            3 => '3 - opt-in via column chooser',
                        ])->default(1)->required(),
                        Select::make('align')->options(['left' => 'Left', 'right' => 'Right', 'center' => 'Center'])->default('left')->required(),
                        Toggle::make('sortable')->default(true),
                    ]),
                    Grid::make(2)->schema([
                        Toggle::make('wrap')->default(false),
                        Toggle::make('link')->label('Link to the record')->default(false)->visible($isList),
                    ]),
                ])
                ->itemLabel(fn (array $state): ?string => is_string($state['field'] ?? null) && $state['field'] !== '' ? $state['field'] : null)
                ->reorderable()
                ->collapsible()
                ->addActionLabel('Add column')
                ->minItems(1)
                ->required(),
            Fieldset::make('Default sort')
                ->schema([
                    Toggle::make('default_sort_enabled')->label('Set a default sort')->live(),
                    Select::make('default_sort_field')
                        ->label('Field')
                        ->options($fieldOptions)
                        ->visible(fn (Get $get): bool => (bool) $get('default_sort_enabled')),
                    Select::make('default_sort_direction')
                        ->label('Direction')
                        ->options(['asc' => 'Ascending', 'desc' => 'Descending'])
                        ->default('desc')
                        ->visible(fn (Get $get): bool => (bool) $get('default_sort_enabled')),
                ])
                ->columns(3),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function panelsSchema(): array
    {
        $fieldOptions = $this->fieldOptions();
        $isDetail = $this->viewName === 'detail';

        return [
            Repeater::make('tabs')
                ->label('Tabs (optional)')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('key')->required()->rule('regex:/^[a-z][a-z0-9_]{0,40}$/'),
                        TextInput::make('label')->required(),
                    ]),
                ])
                ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) && $state['label'] !== '' ? $state['label'] : null)
                ->reorderable()
                ->collapsible()
                ->addActionLabel('Add tab'),
            Repeater::make('panels')
                ->label('Panels')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('key')->required()->rule('regex:/^[a-z][a-z0-9_]{0,40}$/'),
                        TextInput::make('label')->required(),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('tab')->helperText('Must match a tab key above, or leave blank for the first tab.'),
                        Select::make('columns')->label('Grid columns')->options([1 => '1', 2 => '2'])->default(2)->required(),
                        Toggle::make('collapsed')->label('Collapsed by default'),
                    ]),
                    self::visibleWhenFieldset($fieldOptions),
                    Repeater::make('slots')
                        ->label('Fields')
                        ->schema([
                            Grid::make(2)->schema([
                                Select::make('field')->options($fieldOptions)->searchable()->required(),
                                TextInput::make('label')->label('Label override')->helperText("Blank uses the field's own label."),
                            ]),
                            Grid::make(3)->schema([
                                Select::make('span')->options(['half' => 'Half width', 'full' => 'Full width'])->default('half')->required(),
                                Toggle::make('readonly'),
                                $isDetail
                                    ? Toggle::make('hide_when_empty')->label('Hide when empty')->default(true)
                                    : Select::make('required_override')
                                        ->label('Required')
                                        ->options(['' => 'Use field default', '1' => 'Required', '0' => 'Optional'])
                                        ->default(''),
                            ]),
                            self::visibleWhenFieldset($fieldOptions),
                        ])
                        ->itemLabel(fn (array $state): ?string => is_string($state['field'] ?? null) && $state['field'] !== '' ? $state['field'] : null)
                        ->reorderable()
                        ->collapsible()
                        ->addActionLabel('Add field')
                        ->minItems(1)
                        ->required(),
                ])
                ->itemLabel(fn (array $state): ?string => is_string($state['label'] ?? null) && $state['label'] !== '' ? $state['label'] : null)
                ->reorderable()
                ->collapsible()
                ->addActionLabel('Add panel')
                ->minItems(1)
                ->required(),
        ];
    }

    /**
     * @param  array<string, string>  $fieldOptions
     */
    private static function visibleWhenFieldset(array $fieldOptions): Fieldset
    {
        return Fieldset::make('Conditional visibility')
            ->schema([
                Toggle::make('visible_when_enabled')->label('Only show conditionally')->live(),
                Select::make('visible_when_field')
                    ->label('Field')
                    ->options($fieldOptions)
                    ->visible(fn (Get $get): bool => (bool) $get('visible_when_enabled')),
                Select::make('visible_when_operator')
                    ->label('Condition')
                    ->options(self::OPERATORS)
                    ->default('eq')
                    ->live()
                    ->visible(fn (Get $get): bool => (bool) $get('visible_when_enabled')),
                TextInput::make('visible_when_value')
                    ->label('Value')
                    ->visible(fn (Get $get): bool => (bool) $get('visible_when_enabled')
                        && ! in_array($get('visible_when_operator'), ['empty', 'not_empty'], true)),
            ])
            ->columns(4);
    }
}
