<?php

namespace App\Filament\Pages;

use App\Models\Metadata\Module;
use App\Models\User;
use App\Support\MetadataRepository;
use App\Support\RuntimeMailConfigurator;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * S-4.7 (settings half; global search is a separate, unbuilt piece of this
 * task -- see the class docblock's own note below): the UI for the Settings
 * store Z-4.1 already built (App\Support\Settings, RuntimeMailConfigurator).
 * Company profile / branding / email / telephony all go through
 * Settings::set(), the tenancy-ready rule 3 store, never .env. "Enabled
 * modules" is different -- it toggles Module::$enabled directly (the column
 * BuildsResourceFromMetadata::canAccess() now actually reads, via this same
 * pass), not a Settings-store key, since that's the one real switch that
 * already existed and needed wiring up rather than a new key to invent.
 *
 * Password-type fields (mail.password, telephony.webhook_secret) are never
 * pre-filled with the decrypted value on load -- only overwritten if the
 * admin types a new one, standard practice for a secret you don't need to
 * redisplay to prove it's set.
 *
 * @property Form $form
 */
class CompanySettings extends Page implements HasForms
{
    use InteractsWithForms;

    private const MAIL_ENCRYPTIONS = ['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'];

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $slug = 'settings';

    protected static string $view = 'filament.pages.company-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'Settings';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->color('success')
                ->action('save'),
        ];
    }

    public function mount(): void
    {
        $settings = app(Settings::class);

        $modules = [];
        foreach (Module::query()->orderBy('label')->get(['key', 'enabled']) as $module) {
            $modules[(string) $module->key] = (bool) $module->enabled;
        }

        $this->data = [
            'company_name' => $settings->get('company.name'),
            'company_timezone' => $settings->get('company.timezone'),
            'business_hours' => $settings->get('company.business_hours'),
            'branding_primary_color' => $settings->get('branding.primary_color'),
            'branding_logo_url' => $settings->get('branding.logo_url'),
            'mail_host' => $settings->get('mail.host'),
            'mail_port' => $settings->get('mail.port', 587),
            'mail_encryption' => $settings->get('mail.encryption', 'tls'),
            'mail_username' => $settings->get('mail.username'),
            'mail_password' => null,
            'mail_from_address' => $settings->get('mail.from_address'),
            'mail_from_name' => $settings->get('mail.from_name'),
            'telephony_provider' => $settings->get('telephony.provider'),
            'telephony_webhook_secret' => null,
            'modules' => $modules,
        ];

        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        $moduleToggles = [];
        foreach (Module::query()->orderBy('label')->get(['key', 'label']) as $module) {
            $moduleToggles[] = Toggle::make('modules.'.$module->key)->label((string) $module->label);
        }

        return $form
            ->schema([
                Section::make('Company profile')
                    ->schema([
                        TextInput::make('company_name')->label('Company name'),
                        TextInput::make('company_timezone')->label('Timezone')->helperText('e.g. America/Toronto'),
                        TextInput::make('business_hours')->label('Business hours')->helperText('e.g. Mon-Fri 9:00-17:00'),
                    ])
                    ->columns(3),
                Section::make('Branding')
                    ->schema([
                        ColorPicker::make('branding_primary_color')->label('Primary colour'),
                        TextInput::make('branding_logo_url')->label('Logo URL')->url(),
                    ])
                    ->columns(2),
                Section::make('Email (SMTP)')
                    ->description('Used to build the runtime mailer -- RuntimeMailConfigurator::apply().')
                    ->schema([
                        TextInput::make('mail_host')->label('Host'),
                        TextInput::make('mail_port')->label('Port')->numeric(),
                        Select::make('mail_encryption')->label('Encryption')->options(self::MAIL_ENCRYPTIONS),
                        TextInput::make('mail_username')->label('Username'),
                        TextInput::make('mail_password')->label('Password')->password()->revealable()
                            ->helperText('Leave blank to keep the current password.'),
                        TextInput::make('mail_from_address')->label('From address')->email(),
                        TextInput::make('mail_from_name')->label('From name'),
                    ])
                    ->columns(3),
                Section::make('Telephony')
                    ->description('No dialer or SMS integration (out of scope) -- call records are written by an external system through the API; this is that integration\'s own credential.')
                    ->schema([
                        TextInput::make('telephony_provider')->label('Provider'),
                        TextInput::make('telephony_webhook_secret')->label('Webhook secret')->password()->revealable()
                            ->helperText('Leave blank to keep the current secret.'),
                    ])
                    ->columns(2),
                Section::make('Enabled modules')
                    ->description('A disabled module disappears from navigation and every one of its pages, for every user.')
                    ->schema($moduleToggles)
                    ->columns(3),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $settings = app(Settings::class);
        $actorId = self::currentUserId();

        $settings->set('company.name', $state['company_name'] ?? null, group: 'company', updatedBy: $actorId);
        $settings->set('company.timezone', $state['company_timezone'] ?? null, group: 'company', updatedBy: $actorId);
        $settings->set('company.business_hours', $state['business_hours'] ?? null, group: 'company', updatedBy: $actorId);

        $settings->set('branding.primary_color', $state['branding_primary_color'] ?? null, group: 'branding', updatedBy: $actorId);
        $settings->set('branding.logo_url', $state['branding_logo_url'] ?? null, group: 'branding', updatedBy: $actorId);

        $settings->set('mail.host', $state['mail_host'] ?? null, group: 'mail', updatedBy: $actorId);
        $settings->set('mail.port', $state['mail_port'] ?? null, group: 'mail', updatedBy: $actorId);
        $settings->set('mail.encryption', $state['mail_encryption'] ?? null, group: 'mail', updatedBy: $actorId);
        $settings->set('mail.username', $state['mail_username'] ?? null, group: 'mail', updatedBy: $actorId);
        if (filled($state['mail_password'] ?? null)) {
            $settings->set('mail.password', $state['mail_password'], secret: true, group: 'mail', updatedBy: $actorId);
        }
        $settings->set('mail.from_address', $state['mail_from_address'] ?? null, group: 'mail', updatedBy: $actorId);
        $settings->set('mail.from_name', $state['mail_from_name'] ?? null, group: 'mail', updatedBy: $actorId);

        $settings->set('telephony.provider', $state['telephony_provider'] ?? null, group: 'telephony', updatedBy: $actorId);
        if (filled($state['telephony_webhook_secret'] ?? null)) {
            $settings->set('telephony.webhook_secret', $state['telephony_webhook_secret'], secret: true, group: 'telephony', updatedBy: $actorId);
        }

        foreach ((array) ($state['modules'] ?? []) as $moduleKey => $enabled) {
            Module::query()->where('key', $moduleKey)->update(['enabled' => (bool) $enabled]);
        }
        app(MetadataRepository::class)->bump();

        app(RuntimeMailConfigurator::class)->apply();

        Notification::make()->title('Settings saved')->success()->send();
    }

    private static function currentUserId(): ?string
    {
        /** @var User|null $user */
        $user = Filament::auth()->user();

        return $user?->id;
    }
}
