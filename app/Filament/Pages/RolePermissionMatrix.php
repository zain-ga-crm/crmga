<?php

namespace App\Filament\Pages;

use App\Models\Metadata\Module;
use App\Models\Role;
use App\Models\RoleModulePermission;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * S-4.6: modules down, actions across. One row per (role, module) --
 * RoleModulePermission already has one via Acl::registerRole()/registerModule()
 * (see AppServiceProvider's Role::created hook) -- this page only ever reads
 * and updates those rows, it never creates the matrix structure itself
 * beyond a defensive re-sync (registerRole() is idempotent: firstOrCreate)
 * in case a module was added without the observer having run.
 *
 * Each action is a SelectColumn -- Filament's built-in inline-editable
 * select, so a cell edit is a real dropdown, not a separate edit action.
 * "Bulk row/column set" (BACKEND_BRIEF's own phrasing) means: one action
 * per row that sets all seven of that row's actions at once, and one
 * table-wide header action that sets one action-column across every row.
 */
class RolePermissionMatrix extends Page implements HasTable
{
    use InteractsWithTable;

    private const LEVEL_COLORS = [
        'all' => '#22c55e',
        'group' => '#3b82f6',
        'owner' => '#f59e0b',
        'none' => '#ef4444',
        'not_set' => '#6b7280',
    ];

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Role Permissions';

    protected static ?string $slug = 'roles/permissions';

    protected static string $view = 'filament.pages.role-permission-matrix';

    #[Url(as: 'role')]
    public ?string $roleId = null;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'Role Permissions';
    }

    public function mount(): void
    {
        if ($this->roleId === null || Role::query()->whereKey($this->roleId)->doesntExist()) {
            $firstId = Role::query()->orderBy('name')->value('id');
            $this->roleId = is_string($firstId) ? $firstId : null;
        }

        $this->syncMatrixRows();
    }

    public function updatedRoleId(): void
    {
        $this->syncMatrixRows();
        $this->resetTable();
    }

    /**
     * @return array<string, string>
     */
    public function roleOptions(): array
    {
        $options = [];
        foreach (Role::query()->orderBy('name')->get(['id', 'name']) as $role) {
            $options[$role->id] = $role->name;
        }

        return $options;
    }

    private function currentRole(): ?Role
    {
        return $this->roleId !== null ? Role::query()->find($this->roleId) : null;
    }

    private function syncMatrixRows(): void
    {
        $role = $this->currentRole();
        if ($role !== null) {
            app(Acl::class)->registerRole($role->id);
        }
    }

    /**
     * @return array<string, string>
     */
    private function moduleLabels(): array
    {
        $labels = [];
        foreach (Module::query()->orderBy('sort_order')->get(['key', 'label']) as $module) {
            $labels[$module->key] = $module->label;
        }

        return $labels;
    }

    public function table(Table $table): Table
    {
        // currentRole() is resolved lazily inside the closures below, not
        // captured up here -- table() runs during Livewire's boot() phase,
        // before this request's property sync applies a new roleId from the
        // role select (the same stale-closure fix used across every other
        // module-selector page in this app).
        $moduleLabels = $this->moduleLabels();

        return $table
            ->query(fn (): Builder => ($role = $this->currentRole()) !== null
                ? RoleModulePermission::query()->where('role_id', $role->id)
                : RoleModulePermission::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('module_key')
                    ->label('Module')
                    ->getStateUsing(fn (RoleModulePermission $record): string => $moduleLabels[$record->module_key] ?? $record->module_key)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('module_key', 'like', "%{$search}%")),
                ...array_map(
                    fn (string $action): SelectColumn => self::levelColumn($action),
                    Acl::ACTIONS,
                ),
            ])
            ->headerActions([
                Action::make('setColumn')
                    ->label('Set a column')
                    ->icon('heroicon-o-arrows-up-down')
                    ->color('gray')
                    ->form([
                        Select::make('action')->label('Action')->options(array_combine(Acl::ACTIONS, Acl::ACTIONS))->required(),
                        Select::make('level')->label('Access level')->options(self::levelOptions())->required(),
                    ])
                    ->action(function (array $data): void {
                        $role = $this->currentRole();
                        $action = $data['action'] ?? null;
                        if ($role === null || ! is_string($action) || ! in_array($action, Acl::ACTIONS, true)) {
                            return;
                        }

                        RoleModulePermission::query()->where('role_id', $role->id)->update([$action => $data['level']]);

                        Notification::make()->title("Set {$action} for every module")->success()->send();
                    }),
            ])
            ->actions([
                Action::make('setRow')
                    ->label('Set row')
                    ->icon('heroicon-o-arrows-right-left')
                    ->form([
                        Select::make('level')->label('Access level for every action')->options(self::levelOptions())->required(),
                    ])
                    ->action(function (RoleModulePermission $record, array $data): void {
                        $record->update(array_fill_keys(Acl::ACTIONS, $data['level']));
                        Notification::make()->title('Row updated')->success()->send();
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('No role selected');
    }

    private static function levelColumn(string $action): SelectColumn
    {
        return SelectColumn::make($action)
            ->label(ucwords(str_replace('_', ' ', $action)))
            ->options(self::levelOptions())
            ->selectablePlaceholder(false)
            ->extraInputAttributes(fn (mixed $state): array => [
                'style' => 'background-color: '.(self::LEVEL_COLORS[self::valueOf($state)] ?? '#6b7280').'33',
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function levelOptions(): array
    {
        $options = [];
        foreach (AccessLevel::cases() as $level) {
            $options[$level->value] = ucwords(str_replace('_', ' ', $level->value));
        }

        return $options;
    }

    private static function valueOf(mixed $state): string
    {
        if ($state instanceof AccessLevel) {
            return $state->value;
        }

        return is_string($state) ? $state : '';
    }
}
