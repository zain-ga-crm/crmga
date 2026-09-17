<x-filament-panels::page>
    @if (! $this->isEnrolling && ! $this->user()->hasTwoFactorEnabled())
        <x-filament::section>
            <p>{{ __('Add an extra layer of security to your account by requiring an authenticator app code at sign-in.') }}</p>

            <x-filament::button wire:click="startEnrolling" class="mt-4">
                {{ __('Enable two-factor authentication') }}
            </x-filament::button>
        </x-filament::section>
    @elseif ($this->isEnrolling)
        <x-filament::section>
            <div class="flex flex-col items-start gap-4">
                <div>{!! $this->qrCodeSvg() !!}</div>

                <p class="fi-caption">
                    {{ __('Scan this with your authenticator app, then enter the code it shows below.') }}
                </p>

                @if ($this->pendingRecoveryCodes)
                    <div>
                        <p class="fi-caption font-semibold">{{ __('Backup codes -- save these somewhere safe, they will not be shown again:') }}</p>
                        <ul class="font-mono text-sm">
                            @foreach ($this->pendingRecoveryCodes as $code)
                                <li>{{ $code }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            <form wire:submit="confirm" class="mt-4">
                {{ $this->form }}

                <x-filament-panels::form.actions
                    :actions="[
                        \Filament\Actions\Action::make('confirm')->label(__('Confirm'))->submit('confirm'),
                        \Filament\Actions\Action::make('done')->label(__('Done'))->color('gray')->action('finishEnrolling'),
                    ]"
                />
            </form>
        </x-filament::section>
    @else
        <x-filament::section>
            <p>{{ __('Two-factor authentication is enabled on your account.') }}</p>

            <x-filament::button wire:click="disable" color="danger" class="mt-4">
                {{ __('Disable two-factor authentication') }}
            </x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
