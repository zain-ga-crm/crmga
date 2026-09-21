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
 * S-4.5: the Hot/Warm aggregate view. hot_lead/warm_lead are only registered
 * on the three modules ContactableModuleRegistry::hotWarmModules() lists
 * (leads, companies, students) -- a module selector plus a Hot/Warm toggle,
 * same module-selector pattern as DoNotCallList and the Studio pages.
 */
class HotWarmList extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-fire';

    protected static ?string $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'Hot & Warm';

    protected static ?string $slug = 'hot-warm';

    protected static string $view = 'filament.pages.hot-warm-list';

    public ?string $moduleKey = null;

    public string $temperature = 'hot';

    public static function canAccess(): bool
    {
        return true;
    }

    public function getTitle(): string
    {
        return 'Hot & Warm';
    }

    public function mount(): void
    {
        $this->moduleKey = array_key_first(self::visibleModules());
    }

    public function updatedModuleKey(): void
    {
        $this->resetTable();
    }

    public function updatedTemperature(): void
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
     * @return array<string, string>
     */
    public function temperatureOptions(): array
    {
        return ['hot' => 'Hot', 'warm' => 'Warm'];
    }

    /**
     * Only the modules the current user has at least 'list' access to.
     *
     * @return array<string, array{label: string, model: class-string<Model>, resource: class-string<FilamentResource>}>
     */
    private static function visibleModules(): array
    {
        $user = self::currentUser();

        $modules = [];
        foreach (ContactableModuleRegistry::hotWarmModules() as $key => $config) {
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
        // currentConfig()/temperature are resolved lazily, inside each closure
        // below -- see DoNotCallList's own table() for why capturing them
        // eagerly here would bind to the PREVIOUS module/temperature.
        return $table
            ->query(fn (): Builder => ($config = $this->currentConfig()) !== null
                ? $config['model']::query()->where($this->temperatureColumn(), true)
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
            ->emptyStateHeading(fn (): string => "No {$this->temperature} records on this module");
    }

    private function temperatureColumn(): string
    {
        return $this->temperature === 'warm' ? 'warm_lead' : 'hot_lead';
    }
}
