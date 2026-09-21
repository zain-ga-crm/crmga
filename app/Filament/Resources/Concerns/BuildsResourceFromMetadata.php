<?php

namespace App\Filament\Resources\Concerns;

use App\Models\User;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use App\Support\Acl\FieldAccess;
use App\Support\Filament\Concerns\ReadsCompiledMetadata;
use App\Support\Filament\EmptyStates;
use App\Support\Filament\FieldTypeRegistry;
use BackedEnum;
use Closure;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Exporter;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component as FormComponent;
use Filament\Forms\Components\Field as FormField;
use Filament\Forms\Components\Section as FormSection;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\Tabs as FormTabs;
use Filament\Forms\Components\Tabs\Tab as FormTab;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Component as InfolistComponent;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\Tabs as InfolistTabs;
use Filament\Infolists\Components\Tabs\Tab as InfolistTab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\ExportBulkAction;
use Filament\Tables\Columns\Column as TableColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * S-2.1: the DynamicResource mechanism -- builds a Resource's form, table,
 * infolist (detail view) and filters entirely from tenant_layouts +
 * tenant_fields (through FieldTypeRegistry), rather than hand-coding one per
 * module. A concrete Resource just declares its model and moduleKey(); this
 * trait does the rest.
 *
 * Deliberately NOT a single polymorphic route handling every module by a
 * runtime parameter -- Filament Resources are one class per model, and the
 * shipped entities each carry real business logic (HasAcl, HasCustomFields,
 * ...) a generic model would bypass (see ApiModuleRegistry's own docblock;
 * same reasoning applies here). This trait is the *shared mechanism*;
 * LeadResource, CompanyResource etc. are the thin per-model shells around it.
 *
 * Not resolvable from tenant_fields/tenant_layouts alone, and deliberately
 * left out of this pass: a virtual/computed list column (e.g. the frozen
 * layout contract's own "flags" example) has no field metadata to build
 * from -- silently omitted rather than guessed at. assigned_user_id and the
 * base Eloquent timestamps are the one exception (see fieldMetaFor()) since
 * every Contactable-based module has them.
 *
 * Every helper below is called via self::, not static:: -- these are private
 * implementation details, not meant to be overridden per-module, and
 * static:: on a private method is flagged by phpstan as unsafe late static
 * binding (a subclass could shadow it with an unrelated private method of
 * the same name). moduleKey()/getPages()/getUrl() are the genuine exceptions:
 * moduleKey() is abstract (every concrete Resource must supply its own), and
 * getPages()/getUrl() are Filament's own polymorphic Resource statics.
 */
trait BuildsResourceFromMetadata
{
    use ReadsCompiledMetadata;

    private const OWNER_FIELD = 'assigned_user_id';

    /**
     * S-4.5: a real column on every Contactable table (the shared
     * contactable() migration macro), but only default-excluded from a
     * module's list once it's actually registered as metadata -- see its one
     * use in buildFilters() below, which special-cases the TernaryFilter this
     * field gets to start on "only false" rather than "all".
     */
    private const DO_NOT_CALL_FIELD = 'do_not_call';

    public static function form(Form $form): Form
    {
        $module = self::compiledModule();
        $layout = self::assoc($module['layouts'] ?? null)['edit'] ?? null;

        if (! is_array($layout)) {
            return $form;
        }

        $fields = self::fieldsMap($module);

        return $form->schema(self::buildFormSchema(self::assoc($layout['content'] ?? null), $fields, self::currentUser()));
    }

    public static function table(Table $table): Table
    {
        $module = self::compiledModule();
        $layout = self::assoc($module['layouts'] ?? null)['list'] ?? null;
        $user = self::currentUser();
        $moduleLabel = self::str($module['label_plural'] ?? null, self::str($module['label'] ?? null, self::moduleKey()));

        $table = $table
            ->emptyStateHeading(EmptyStates::heading($moduleLabel))
            ->emptyStateDescription(EmptyStates::description())
            ->emptyStateIcon(EmptyStates::icon());

        if (! is_array($layout)) {
            return $table;
        }

        $fields = self::fieldsMap($module);
        $content = self::assoc($layout['content'] ?? null);

        $columns = [];
        $needsOwnerEagerLoad = false;
        foreach (self::listOfArrays($content['columns'] ?? null) as $columnDef) {
            $column = self::buildTableColumn($columnDef, $fields, $user);
            if ($column !== null) {
                $columns[] = $column;
            }
            if (self::str($columnDef['field'] ?? null) === self::OWNER_FIELD) {
                $needsOwnerEagerLoad = true;
            }
        }

        $table = $table
            ->columns($columns)
            ->filters(self::buildFilters($module, $fields, $user))
            ->bulkActions(self::buildBulkActions($user));

        // Z-4.4: the owner column reads assignedUser.name for every row --
        // without this, every list page (10-25 rows) triggers one extra
        // query per row just to resolve the owner's name.
        if ($needsOwnerEagerLoad) {
            $table = $table->modifyQueryUsing(fn (Builder $query): Builder => $query->with('assignedUser'));
        }

        $defaultSort = $content['default_sort'] ?? null;
        if (is_array($defaultSort)) {
            $field = self::str($defaultSort['field'] ?? null);
            $direction = self::str($defaultSort['direction'] ?? null, 'asc');
            if ($field !== '') {
                $table = $table->defaultSort($field, $direction);
            }
        }

        return $table;
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $module = self::compiledModule();
        $layout = self::assoc($module['layouts'] ?? null)['detail'] ?? null;

        if (! is_array($layout)) {
            return $infolist;
        }

        $fields = self::fieldsMap($module);

        return $infolist->schema(self::buildInfolistSchema(self::assoc($layout['content'] ?? null), $fields, self::currentUser()));
    }

    // Deliberately NOT overriding canViewAny()/canView()/canCreate()/canEdit()/
    // canDelete() here: Filament's own defaults already delegate to
    // static::can('viewAny'|'view'|'create'|'update'|'delete', ...), which
    // Laravel resolves to this module's real Policy (e.g. LeadPolicy extends
    // CrmPolicy) -- the exact same Acl::effective() calls this trait would
    // otherwise duplicate, but CrmPolicy also does the Owner-level per-record
    // check (assigned_user_id === $user->id) that a naive module-level-only
    // check here would miss entirely. One ACL enforcement point, reused by
    // the query scope, the API, and now the UI -- never three copies that can
    // drift apart.

    /**
     * canAccess() IS overridden, unlike the Policy-backed methods above --
     * this is a different axis (a tenant admin switching a whole module off
     * via the Settings screen's "Enabled modules" section, S-4.7/rule 3),
     * not a re-implementation of per-record ACL. Filament's own
     * registerNavigationItems() and CanAuthorizeResourceAccess both already
     * call canAccess(), so overriding it here is the one place this needs to
     * be wired for every DynamicResource at once.
     *
     * Unlike form()/table()/infolist(), an unregistered module here is not
     * fatal: registerNavigationItems() calls canAccess() for every panel
     * Resource on every page load (building the sidebar), not just the one
     * actually being rendered, so this runs against modules that may
     * legitimately not exist yet (a fresh test database, a tenant whose
     * metadata seeder hasn't run). compiledModule() throwing there is right
     * for a real render; here it just means "nothing to access".
     */
    public static function canAccess(): bool
    {
        try {
            $enabled = self::compiledModule()['enabled'] ?? true;
        } catch (RuntimeException) {
            return false;
        }

        return (bool) $enabled && static::canViewAny();
    }

    /**
     * S-2.4: the Exporter class for this module (LeadExporter, CompanyExporter,
     * ...), or null to skip export entirely. Not abstract -- a module that
     * doesn't need export yet just doesn't override this, rather than every
     * Resource being forced to declare one.
     *
     * @return class-string<Exporter>|null
     */
    public static function exporter(): ?string
    {
        return null;
    }

    /**
     * For a ListRecords page's own getHeaderActions() to include alongside
     * CreateAction -- exports every record matching the current
     * filters/search, not just a bulk selection. Returns [] (not null) so a
     * page can just array_merge() this in without a null check.
     *
     * @return array<ExportAction>
     */
    public static function exportHeaderActions(): array
    {
        $exporterClass = static::exporter();
        if ($exporterClass === null) {
            return [];
        }

        if (self::acl()->effective(self::currentUser(), self::moduleKey(), 'export') === AccessLevel::None) {
            return [];
        }

        return [ExportAction::make()->exporter($exporterClass)];
    }

    // ---- form (edit layout) ----

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<int, FormComponent>
     */
    private static function buildFormSchema(array $content, array $fields, User $user): array
    {
        $panels = self::listOfArrays($content['panels'] ?? null);
        $tabs = $content['tabs'] ?? null;
        $watched = self::watchedFieldNames($panels);

        if (! is_array($tabs)) {
            return self::buildFormPanels($panels, null, $fields, $user, $watched);
        }

        $tabList = self::listOfArrays($tabs);
        usort($tabList, fn (array $a, array $b): int => self::int($a['order'] ?? null) <=> self::int($b['order'] ?? null));

        $tabComponents = [];
        foreach ($tabList as $tabDef) {
            $key = self::str($tabDef['key'] ?? null);
            $tabComponents[] = FormTab::make(self::str($tabDef['label'] ?? null))
                ->schema(self::buildFormPanels($panels, $key, $fields, $user, $watched));
        }

        return [FormTabs::make()->tabs($tabComponents)];
    }

    /**
     * @param  list<array<string, mixed>>  $panels
     * @param  array<string, array<string, mixed>>  $fields
     * @param  list<string>  $watched
     * @return array<int, FormComponent>
     */
    private static function buildFormPanels(array $panels, ?string $tabKey, array $fields, User $user, array $watched): array
    {
        $matching = array_filter($panels, fn (array $p): bool => self::panelTabKey($p) === $tabKey);
        $sorted = collect($matching)->sortBy(fn (array $p) => self::int($p['order'] ?? null))->values()->all();

        return array_map(
            fn (array $panelDef): FormComponent => self::buildFormPanelSection($panelDef, $fields, $user, $watched),
            $sorted,
        );
    }

    /**
     * @param  array<string, mixed>  $panelDef
     * @param  array<string, array<string, mixed>>  $fields
     * @param  list<string>  $watched
     */
    private static function buildFormPanelSection(array $panelDef, array $fields, User $user, array $watched): FormComponent
    {
        $components = [];
        foreach (self::listOfLists($panelDef['rows'] ?? null) as $row) {
            foreach (self::listOfArrays($row) as $slot) {
                $component = self::buildFormFieldComponent($slot, $fields, $user, $watched);
                if ($component !== null) {
                    $components[] = $component;
                }
            }
        }

        $section = FormSection::make(self::str($panelDef['label'] ?? null))
            ->schema($components)
            ->columns(self::int($panelDef['columns'] ?? null, 2))
            ->collapsed((bool) ($panelDef['collapsed'] ?? false));

        $visibleWhen = $panelDef['visible_when'] ?? null;
        if (is_array($visibleWhen)) {
            $section = $section->visible(self::visibleWhenFormClosure(self::assoc($visibleWhen)));
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, array<string, mixed>>  $fields
     * @param  list<string>  $watched
     */
    private static function buildFormFieldComponent(array $slot, array $fields, User $user, array $watched): ?FormField
    {
        $name = self::str($slot['field'] ?? null);
        if ($name === '') {
            return null;
        }

        if ($name === self::OWNER_FIELD) {
            $component = self::ownerFormComponent();
        } else {
            $access = self::acl()->fieldAccess($user, self::moduleKey(), $name);
            if ($access === FieldAccess::Hidden) {
                return null;
            }

            $meta = self::fieldMetaFor($name, $fields);
            if ($meta === null) {
                return null;
            }

            $label = $slot['label'] ?? null;
            if ($label !== null) {
                $meta = array_merge($meta, ['label' => $label]);
            }

            $component = app(FieldTypeRegistry::class)->formComponent($meta);

            if ($access === FieldAccess::ReadOnly) {
                $component = $component->disabled();
            }
        }

        if (($slot['readonly'] ?? false) === true) {
            $component = $component->disabled();
        }

        if (array_key_exists('required_override', $slot) && $slot['required_override'] !== null) {
            $component = $component->required((bool) $slot['required_override']);
        }

        if (self::str($slot['span'] ?? null, 'half') === 'full') {
            $component = $component->columnSpanFull();
        }

        if (in_array($name, $watched, true)) {
            $component = $component->live();
        }

        $visibleWhen = $slot['visible_when'] ?? null;
        if (is_array($visibleWhen)) {
            $component = $component->visible(self::visibleWhenFormClosure(self::assoc($visibleWhen)));
        }

        return $component;
    }

    private static function ownerFormComponent(): FormField
    {
        return FormSelect::make(self::OWNER_FIELD)
            ->label('Owner')
            ->relationship('assignedUser', 'name')
            ->searchable();
    }

    // ---- table (list layout) + filters (search layout) ----

    /**
     * @param  array<string, mixed>  $columnDef
     * @param  array<string, array<string, mixed>>  $fields
     */
    private static function buildTableColumn(array $columnDef, array $fields, User $user): ?TableColumn
    {
        $name = self::str($columnDef['field'] ?? null);
        if ($name === '') {
            return null;
        }

        if (self::acl()->fieldAccess($user, self::moduleKey(), $name) === FieldAccess::Hidden) {
            return null;
        }

        if ($name === self::OWNER_FIELD) {
            $column = self::ownerTableColumn();
        } else {
            $meta = self::fieldMetaFor($name, $fields);
            if ($meta === null) {
                return null;
            }
            $column = app(FieldTypeRegistry::class)->tableColumn($meta);
        }

        $label = $columnDef['label'] ?? null;
        if (is_string($label)) {
            $column = $column->label($label);
        }

        if (array_key_exists('sortable', $columnDef)) {
            $column = $column->sortable(((bool) $columnDef['sortable']) && $column->isSortable());
        }

        $priority = self::int($columnDef['priority'] ?? null, 1);
        $column = $column->toggleable(isToggledHiddenByDefault: $priority === 3);

        // wrap() is a TextColumn-only method (declared directly on it, not the
        // shared Column base) -- guard rather than assume every column type
        // built above (IconColumn, ImageColumn, ...) has it.
        if ($column instanceof TextColumn && ($columnDef['wrap'] ?? false) === true) {
            $column = $column->wrap();
        }

        $column = match (self::str($columnDef['align'] ?? null, 'left')) {
            'right' => $column->alignRight(),
            'center' => $column->alignCenter(),
            default => $column,
        };

        if (($columnDef['link'] ?? false) === true && isset(static::getPages()['view'])) {
            $column = $column->url(fn (Model $record): string => static::getUrl('view', ['record' => $record]));
        }

        $width = self::intOrNull($columnDef['width'] ?? null);
        if ($width !== null) {
            $column = $column->extraHeaderAttributes(['style' => "width: {$width}px"]);
        }

        return $column;
    }

    private static function ownerTableColumn(): TableColumn
    {
        return TextColumn::make('assignedUser.name')->label('Owner');
    }

    /**
     * @param  array<string, mixed>  $module
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<int, SelectFilter|TernaryFilter>
     */
    private static function buildFilters(array $module, array $fields, User $user): array
    {
        $searchLayout = self::assoc($module['layouts'] ?? null)['search'] ?? null;
        if (! is_array($searchLayout)) {
            return [];
        }

        $content = self::assoc($searchLayout['content'] ?? null);

        $filters = [];
        foreach (self::listOfArrays($content['columns'] ?? null) as $columnDef) {
            $name = self::str($columnDef['field'] ?? null);

            if ($name === '' || $name === self::OWNER_FIELD || self::acl()->fieldAccess($user, self::moduleKey(), $name) === FieldAccess::Hidden) {
                continue;
            }

            $meta = self::fieldMetaFor($name, $fields);
            if ($meta === null) {
                continue;
            }

            $filter = match ($meta['type'] ?? null) {
                'enum' => SelectFilter::make($name)->options(app(FieldTypeRegistry::class)->selectOptions($meta)),
                // do_not_call starts on "only false" (Filament's own initial
                // state), matching the query's own default exclusion above --
                // still switchable to "all" or "only true" from the dropdown.
                'bool' => $name === self::DO_NOT_CALL_FIELD
                    ? TernaryFilter::make($name)->default(false)
                    : TernaryFilter::make($name),
                // Every other type would need a custom Filter::make()->form([...])
                // text-search closure rather than a stock filter type -- left for
                // a later pass; enum/bool cover this module's real search fields.
                default => null,
            };

            if ($filter !== null) {
                $filters[] = $filter;
            }
        }

        return $filters;
    }

    /**
     * S-2.4: the table's bulk-action bar. Gated on the same ACL actions as
     * everything else here -- 'delete' for DeleteBulkAction, 'export' for
     * ExportBulkAction (only when the concrete Resource declares an
     * exporter()). Not per-record beyond what the table's own query scope
     * (AppliesRecordAccess) already guarantees: an Owner-level user can only
     * ever select rows they're allowed to see in the first place, so a
     * module-level "has any delete access" check is sufficient here, the
     * same reasoning the row-level EditAction/DeleteAction already rely on
     * via the Policy.
     *
     * @return array<BulkActionGroup>
     */
    private static function buildBulkActions(User $user): array
    {
        $actions = [];

        if (self::acl()->effective($user, self::moduleKey(), 'delete') !== AccessLevel::None) {
            $actions[] = DeleteBulkAction::make();
        }

        $exporterClass = static::exporter();
        if ($exporterClass !== null && self::acl()->effective($user, self::moduleKey(), 'export') !== AccessLevel::None) {
            $actions[] = ExportBulkAction::make()->exporter($exporterClass);
        }

        return $actions === [] ? [] : [BulkActionGroup::make($actions)];
    }

    // ---- infolist (detail layout) ----

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<int, InfolistComponent>
     */
    private static function buildInfolistSchema(array $content, array $fields, User $user): array
    {
        $panels = self::listOfArrays($content['panels'] ?? null);
        $tabs = $content['tabs'] ?? null;

        if (! is_array($tabs)) {
            return self::buildInfolistPanels($panels, null, $fields, $user);
        }

        $tabList = self::listOfArrays($tabs);
        usort($tabList, fn (array $a, array $b): int => self::int($a['order'] ?? null) <=> self::int($b['order'] ?? null));

        $tabComponents = [];
        foreach ($tabList as $tabDef) {
            $key = self::str($tabDef['key'] ?? null);
            $tabComponents[] = InfolistTab::make(self::str($tabDef['label'] ?? null))
                ->schema(self::buildInfolistPanels($panels, $key, $fields, $user));
        }

        return [InfolistTabs::make()->tabs($tabComponents)];
    }

    /**
     * @param  list<array<string, mixed>>  $panels
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<int, InfolistComponent>
     */
    private static function buildInfolistPanels(array $panels, ?string $tabKey, array $fields, User $user): array
    {
        $matching = array_filter($panels, fn (array $p): bool => self::panelTabKey($p) === $tabKey);
        $sorted = collect($matching)->sortBy(fn (array $p) => self::int($p['order'] ?? null))->values()->all();

        return array_map(
            fn (array $panelDef): InfolistComponent => self::buildInfolistPanelSection($panelDef, $fields, $user),
            $sorted,
        );
    }

    /**
     * @param  array<string, mixed>  $panelDef
     * @param  array<string, array<string, mixed>>  $fields
     */
    private static function buildInfolistPanelSection(array $panelDef, array $fields, User $user): InfolistComponent
    {
        $components = [];
        foreach (self::listOfLists($panelDef['rows'] ?? null) as $row) {
            foreach (self::listOfArrays($row) as $slot) {
                $component = self::buildInfolistEntryComponent($slot, $fields, $user);
                if ($component !== null) {
                    $components[] = $component;
                }
            }
        }

        $section = InfolistSection::make(self::str($panelDef['label'] ?? null))
            ->schema($components)
            ->columns(self::int($panelDef['columns'] ?? null, 2))
            ->collapsed((bool) ($panelDef['collapsed'] ?? false));

        $visibleWhen = $panelDef['visible_when'] ?? null;
        if (is_array($visibleWhen)) {
            $field = self::str($visibleWhen['field'] ?? null);
            $operator = self::str($visibleWhen['operator'] ?? null);
            $value = $visibleWhen['value'] ?? null;
            $section = $section->visible(function (?Model $record) use ($field, $operator, $value): bool {
                return $record !== null && self::conditionMatches($record->getAttribute($field), $operator, $value);
            });
        }

        return $section;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  array<string, array<string, mixed>>  $fields
     */
    private static function buildInfolistEntryComponent(array $slot, array $fields, User $user): ?InfolistComponent
    {
        $name = self::str($slot['field'] ?? null);
        if ($name === '') {
            return null;
        }

        if ($name === self::OWNER_FIELD) {
            $entry = self::ownerInfolistEntry();
        } else {
            if (self::acl()->fieldAccess($user, self::moduleKey(), $name) === FieldAccess::Hidden) {
                return null;
            }

            $meta = self::fieldMetaFor($name, $fields);
            if ($meta === null) {
                return null;
            }

            $label = $slot['label'] ?? null;
            if ($label !== null) {
                $meta = array_merge($meta, ['label' => $label]);
            }

            $entry = app(FieldTypeRegistry::class)->infolistEntry($meta);
        }

        if (self::str($slot['span'] ?? null, 'half') === 'full') {
            $entry = $entry->columnSpanFull();
        }

        $hideWhenEmpty = (bool) ($slot['hide_when_empty'] ?? true);
        $visibleWhen = $slot['visible_when'] ?? null;
        $field = is_array($visibleWhen) ? self::str($visibleWhen['field'] ?? null) : null;
        $operator = is_array($visibleWhen) ? self::str($visibleWhen['operator'] ?? null) : null;
        $value = is_array($visibleWhen) ? ($visibleWhen['value'] ?? null) : null;

        // Both conditions must compose into ONE visible() call -- a second
        // call would silently replace the first rather than AND with it.
        if ($hideWhenEmpty || $field !== null) {
            $entry = $entry->visible(function (mixed $state, ?Model $record) use ($hideWhenEmpty, $field, $operator, $value): bool {
                if ($hideWhenEmpty && blank($state)) {
                    return false;
                }

                if ($field !== null) {
                    $watchedValue = $record?->getAttribute($field);

                    return self::conditionMatches($watchedValue, (string) $operator, $value);
                }

                return true;
            });
        }

        return $entry;
    }

    private static function ownerInfolistEntry(): InfolistComponent
    {
        return TextEntry::make('assignedUser.name')->label('Owner');
    }

    // ---- shared helpers ----

    /**
     * A real metadata field, or a small set of well-known base columns every
     * Contactable-based module has but that Studio never registers as
     * tenant_fields (assigned_user_id is handled separately -- see
     * self::OWNER_FIELD callers -- since it needs a real relationship, not a
     * synthesized type). Anything else (e.g. the frozen contract's own
     * "flags" example, a virtual/computed column) returns null and the
     * caller silently omits it -- not resolvable from tenant_fields alone.
     *
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>|null
     */
    private static function fieldMetaFor(string $name, array $fields): ?array
    {
        if (isset($fields[$name])) {
            return $fields[$name];
        }

        if (in_array($name, ['created_at', 'updated_at'], true)) {
            return [
                'name' => $name,
                'type' => 'datetime',
                'label' => $name === 'created_at' ? 'Created' : 'Updated',
                'help' => null,
                'required' => false,
                'max_length' => null,
                'precision' => null,
                'scale' => null,
                'option_list_id' => null,
                'related_module_id' => null,
                'related_display_field' => null,
                'default_value' => null,
            ];
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $panels
     * @return list<string>
     */
    private static function watchedFieldNames(array $panels): array
    {
        $watched = [];
        foreach ($panels as $panel) {
            $panelCondition = $panel['visible_when'] ?? null;
            if (is_array($panelCondition)) {
                $field = self::str($panelCondition['field'] ?? null);
                if ($field !== '') {
                    $watched[] = $field;
                }
            }

            foreach (self::listOfLists($panel['rows'] ?? null) as $row) {
                foreach (self::listOfArrays($row) as $slot) {
                    $slotCondition = $slot['visible_when'] ?? null;
                    if (is_array($slotCondition)) {
                        $field = self::str($slotCondition['field'] ?? null);
                        if ($field !== '') {
                            $watched[] = $field;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($watched));
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function visibleWhenFormClosure(array $condition): Closure
    {
        $field = self::str($condition['field'] ?? null);
        $operator = self::str($condition['operator'] ?? null);
        $value = $condition['value'] ?? null;

        return function (Get $get) use ($field, $operator, $value): bool {
            return self::conditionMatches($get($field), $operator, $value);
        };
    }

    private static function conditionMatches(mixed $value, string $operator, mixed $expected): bool
    {
        // A cast enum attribute (e.g. Lead::vertical -> LeadVertical) compares
        // as an object, but the layout schema's own "value" is always the
        // plain scalar (e.g. "Refugee") -- unwrap to the enum's backing value
        // first so eq/neq/in/not_in compare like-for-like.
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return match ($operator) {
            'eq' => $value === $expected,
            'neq' => $value !== $expected,
            'in' => is_array($expected) && in_array($value, $expected, true),
            'not_in' => is_array($expected) && ! in_array($value, $expected, true),
            'empty' => blank($value),
            'not_empty' => filled($value),
            default => true,
        };
    }

    /**
     * @param  array<string, mixed>  $panel
     */
    private static function panelTabKey(array $panel): ?string
    {
        $tab = $panel['tab'] ?? null;

        return is_string($tab) ? $tab : null;
    }

    private static function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    private static function acl(): Acl
    {
        return app(Acl::class);
    }

    /**
     * For a panel's own "rows" specifically: a list of rows, each row itself
     * a plain numeric list of 1-2 slot objects -- NOT listOfArrays(), whose
     * per-item assoc() would strip every row's numeric keys and silently
     * leave every row empty (a row is a list, not an object; only what's
     * *inside* each row is object-shaped, see listOfArrays() on $row itself).
     *
     * @return list<list<mixed>>
     */
    private static function listOfLists(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = array_values($item);
            }
        }

        return $out;
    }

    private static function int(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
