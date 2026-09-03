<?php

namespace App\Filament\Pages;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Two-factor enrolment (crmga_Frontend_Design_Spec.docx §7's "optional
 * two-factor enrolment step", built as its own page rather than a second
 * step of ChangePassword's wizard -- see that class's docblock for why).
 * Reachable any time from the user menu, not only at first login.
 *
 * @property Form $form
 */
class Security extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static string $view = 'filament.pages.security';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * Only set for the one render right after enableTwoFactor() -- shown
     * once, never persisted anywhere but this Livewire component's memory
     * for the current confirmation step.
     *
     * @var list<string>|null
     */
    public ?array $pendingRecoveryCodes = null;

    public bool $isEnrolling = false;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return 'Security';
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('code')
                    ->label('Authentication code')
                    ->helperText('Enter the 6-digit code your authenticator app is now showing.')
                    ->required()
                    ->autocomplete('one-time-code'),
            ])
            ->statePath('data');
    }

    public function startEnrolling(): void
    {
        $this->pendingRecoveryCodes = $this->user()->enableTwoFactor();
        $this->isEnrolling = true;
    }

    public function confirm(): void
    {
        $state = $this->form->getState();
        $code = $state['code'] ?? null;

        if (! is_string($code) || ! $this->user()->confirmTwoFactor($code)) {
            Notification::make()
                ->title('That code did not match. Try again.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Two-factor authentication is now enabled')
            ->success()
            ->send();
    }

    public function finishEnrolling(): void
    {
        $this->pendingRecoveryCodes = null;
        $this->isEnrolling = false;
        $this->form->fill();
    }

    public function disable(): void
    {
        $this->user()->disableTwoFactor();
        $this->isEnrolling = false;
        $this->pendingRecoveryCodes = null;

        Notification::make()
            ->title('Two-factor authentication disabled')
            ->success()
            ->send();
    }

    public function qrCodeSvg(): ?string
    {
        $url = $this->user()->twoFactorQrCodeUrl();
        if ($url === null) {
            return null;
        }

        $renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }
}
