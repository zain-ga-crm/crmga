<?php

namespace App\Filament\Pages;

use App\Support\Api\ApiModuleRegistry;
use App\Support\Ingest\FieldMapping;
use App\Support\Ingest\FieldMapRepository;
use App\Support\Ingest\IngestPipeline;
use App\Support\Settings;
use Filament\Facades\Filament;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * S-5.2: integration hub cards (status) + WordPress/Meta configuration + the
 * shared field-mapping editor + a test-payload runner. The backend
 * (FieldMapRepository, config/ingest.php's defaults, the live
 * /api/v1/ingest/* endpoints) already existed in full -- FieldMapRepository's
 * own docblock names this exact screen as its intended UI.
 *
 * Credentials are never re-displayed once saved (same "leave blank to keep
 * the current secret" convention as CompanySettings' telephony_webhook_secret)
 * -- only a configured/not-configured status, consistent with every other
 * secret in this app.
 *
 * @property Form $wordpressForm
 * @property Form $metaForm
 * @property Form $testRunnerForm
 */
class IntegrationHub extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string $view = 'filament.pages.integration-hub';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $slug = 'integrations';

    /** @var array<string, mixed>|null */
    public ?array $wordpressData = [];

    /** @var array<string, mixed>|null */
    public ?array $metaData = [];

    /** @var array<string, mixed>|null */
    public ?array $testRunnerData = [];

    /** @var array<string, mixed>|null */
    public ?array $testRunnerResult = null;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'Integrations';
    }

    public function mount(): void
    {
        $this->wordpressForm->fill(['field_map' => $this->mappingState('wordpress')]);
        $this->metaForm->fill(['field_map' => $this->mappingState('meta')]);
        $this->testRunnerForm->fill(['source' => 'wordpress']);
    }

    /**
     * @return array<int|string, string>
     */
    protected function getForms(): array
    {
        return ['wordpressForm', 'metaForm', 'testRunnerForm'];
    }

    public function wordpressConfigured(): bool
    {
        return $this->settings()->get('ingest.wordpress.api_key') !== null;
    }

    public function metaConfigured(): bool
    {
        return $this->settings()->get('ingest.meta.app_secret') !== null;
    }

    public function wordpressForm(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('api_key')
                    ->label('API key')
                    ->password()
                    ->revealable()
                    ->helperText('Leave blank to keep the current key. Sent by the caller as the X-Api-Key header.'),
                $this->fieldMapRepeater(),
            ])
            ->statePath('wordpressData');
    }

    public function metaForm(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('verify_token')
                    ->label('Verify token')
                    ->helperText('The hub.verify_token Meta sends during webhook subscription setup. Not secret.'),
                TextInput::make('app_secret')
                    ->label('App secret')
                    ->password()
                    ->revealable()
                    ->helperText('Leave blank to keep the current secret. Used to verify the webhook signature.'),
                $this->fieldMapRepeater(),
            ])
            ->statePath('metaData');
    }

    public function testRunnerForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('source')
                    ->label('Source')
                    ->options(['wordpress' => 'WordPress', 'meta' => 'Meta Lead Ads'])
                    ->required()
                    ->live(),
                Textarea::make('payload')
                    ->label('Sample payload (JSON)')
                    ->rows(6)
                    ->required()
                    ->helperText('Previews field mapping and transforms only -- does not validate, dedupe or create a record.'),
            ])
            ->statePath('testRunnerData');
    }

    private function fieldMapRepeater(): Repeater
    {
        $targetFields = array_keys(app(ApiModuleRegistry::class)->fields('leads'));

        return Repeater::make('field_map')
            ->label('Field mapping')
            ->schema([
                Grid::make(2)->schema([
                    TextInput::make('source_field')->label('Source field')->required(),
                    Select::make('target_field')->label('CRM field')->options(array_combine($targetFields, $targetFields))->searchable()->required(),
                ]),
                Grid::make(2)->schema([
                    Select::make('transform')
                        ->label('Transform')
                        ->options([
                            'trim' => 'Trim whitespace',
                            'lower' => 'Lowercase',
                            'upper' => 'Uppercase',
                            'phone' => 'Clean phone number',
                            'name_first' => 'First word (first name)',
                            'name_last' => 'Remaining words (last name)',
                        ])
                        ->placeholder('None'),
                    TextInput::make('default')->label('Default value'),
                ]),
            ])
            ->itemLabel(function (array $state): ?string {
                $sourceField = $state['source_field'] ?? null;
                if (! is_string($sourceField) || $sourceField === '') {
                    return null;
                }

                $targetField = $state['target_field'] ?? null;

                return "{$sourceField} -> ".(is_string($targetField) ? $targetField : '?');
            })
            ->reorderable()
            ->collapsible()
            ->addActionLabel('Add mapping rule');
    }

    public function saveWordpress(): void
    {
        $this->saveSource('wordpress', $this->wordpressForm->getState(), ['api_key']);
    }

    public function saveMeta(): void
    {
        $this->saveSource('meta', $this->metaForm->getState(), ['app_secret'], ['verify_token']);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $secretKeys
     * @param  list<string>  $plainKeys
     */
    private function saveSource(string $source, array $state, array $secretKeys, array $plainKeys = []): void
    {
        foreach ($secretKeys as $key) {
            $value = $state[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $this->settings()->set("ingest.{$source}.{$key}", $value, secret: true);
            }
        }

        foreach ($plainKeys as $key) {
            $value = $state[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $this->settings()->set("ingest.{$source}.{$key}", $value);
            }
        }

        $rows = is_array($state['field_map'] ?? null) ? $state['field_map'] : [];
        $mappings = array_values(array_map(
            fn (mixed $row): FieldMapping => FieldMapping::fromArray($this->stringKeyed($row)),
            $rows,
        ));
        app(FieldMapRepository::class)->setForSource($source, $mappings);

        Notification::make()->title(ucfirst($source).' configuration saved')->success()->send();
    }

    public function runTest(): void
    {
        $state = $this->testRunnerForm->getState();
        $source = is_string($state['source'] ?? null) ? $state['source'] : '';
        $payload = is_string($state['payload'] ?? null) ? $state['payload'] : '';

        $decoded = json_decode($payload, true);
        if (! is_array($decoded)) {
            Notification::make()->title('That isn\'t valid JSON')->danger()->send();

            return;
        }

        $this->testRunnerResult = app(IngestPipeline::class)->mapFields($source, $this->stringKeyed($decoded));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mappingState(string $source): array
    {
        return array_map(
            fn (FieldMapping $mapping): array => $mapping->toArray(),
            app(FieldMapRepository::class)->forSource($source),
        );
    }

    private function settings(): Settings
    {
        return app(Settings::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
