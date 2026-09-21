<?php

namespace App\Filament\Pages;

use App\Models\Lead;
use App\Models\User;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use App\Support\Filament\ContactableModuleRegistry;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource as FilamentResource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * S-4.5: the dedicated Do Not Call view. Every module's own list already
 * excludes do_not_call records by default (BuildsResourceFromMetadata's
 * do_not_call TernaryFilter, defaulted to "only false") -- this page is
 * where that excluded set is actually reviewable, one module at a time via
 * the same module-selector pattern the Studio pages use, scoped to exactly
 * the modules ContactableModuleRegistry::doNotCallModules() lists (every
 * Contactable module, since do_not_call is a column on all of them).
 *
 * Records here are still gated by that module's own ACL 'list'/'view'
 * access -- an Owner-access user only ever sees their own do-not-call
 * records, never the whole tenant's.
 */
class DoNotCallList extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-phone-x-mark';

    protected static ?string $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'Do Not Call';

    protected static ?string $slug = 'do-not-call';

    protected static string $view = 'filament.pages.do-not-call-list';

    public ?string $moduleKey = null;

    public static function canAccess(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return 'Do Not Call';
    }

    public function mount(): void
    {
        $this->moduleKey = array_key_first(self::visibleModules());
    }

    public function updatedModuleKey(): void
    {
        $this->resetTable();
    }

    /**
     * @return array<string, string>
     */
    public function moduleOptions(): array
    {
        $options = [];
        foreach (self::visibleModules() as $key => $config) {
            $options[$key] = $config['label'];
        }

        return $options;
    }

    /**
     * Only the modules the current user has at least 'list' access to --
     * this page has no module-level gate of its own beyond that.
     *
     * @return array<string, array{label: string, model: class-string<Model>, resource: class-string<FilamentResource>}>
     */
    private static function visibleModules(): array
    {
        $user = self::currentUser();

        $modules = [];
        foreach (ContactableModuleRegistry::doNotCallModules() as $key => $config) {
            if ($user->isAdmin() || app(Acl::class)->effective($user, $key, 'list') !== AccessLevel::None) {
                $modules[$key] = $config;
            }
        }

        return $modules;
    }

    private static function currentUser(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /**
     * @return array{label: string, model: class-string<Model>, resource: class-string<FilamentResource>}|null
     */
    private function currentConfig(): ?array
    {
        $modules = self::visibleModules();

        return $this->moduleKey !== null ? ($modules[$this->moduleKey] ?? null) : null;
    }

    public function table(Table $table): Table
    {
        // currentConfig() is resolved lazily, inside each closure below, rather
        // than once up here -- table() runs during Livewire's boot() phase,
        // before this request's property sync applies a new moduleKey from the
        // module select (see FieldManager's own table() for the same fix and
        // why capturing it eagerly here would bind to the PREVIOUS module).
        return $table
            ->query(fn (): Builder => ($config = $this->currentConfig()) !== null
                ? $config['model']::query()->where('do_not_call', true)
                : Lead::query()->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->getStateUsing(fn (Model $record): string => ContactableModuleRegistry::fullNameOf($record)),
                TextColumn::make('primary_email')->label('Email')->searchable(),
                TextColumn::make('phone_mobile')->label('Phone'),
                TextColumn::make('assignedUser.name')->label('Owner'),
            ])
            ->actions([
                Action::make('view')
                    ->label('View')
                    ->url(function (Model $record): ?string {
                        $config = $this->currentConfig();

                        return $config !== null ? $config['resource']::getUrl('view', ['record' => $record]) : null;
                    }),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No do-not-call records on this module');
    }
}
