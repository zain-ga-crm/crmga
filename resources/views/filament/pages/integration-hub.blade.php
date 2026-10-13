<x-filament-panels::page>
    <div class="grid gap-6 lg:grid-cols-2">
        <x-filament::section heading="WordPress">
            <x-slot name="headerEnd">
                <x-filament::badge :color="$this->wordpressConfigured() ? 'success' : 'gray'">
                    {{ $this->wordpressConfigured() ? __('Configured') : __('Not configured') }}
                </x-filament::badge>
            </x-slot>

            <p class="fi-caption">{{ __('Contact Form 7, Gravity Forms, WPForms and Elementor submissions, via the signed X-Api-Key header.') }}</p>

            <form wire:submit="saveWordpress" class="mt-4">
                {{ $this->wordpressForm }}

                <x-filament-panels::form.actions
                    :actions="[\Filament\Actions\Action::make('saveWordpress')->label(__('Save'))->submit('saveWordpress')]"
                />
            </form>
        </x-filament::section>

        <x-filament::section heading="Meta Lead Ads">
            <x-slot name="headerEnd">
                <x-filament::badge :color="$this->metaConfigured() ? 'success' : 'gray'">
                    {{ $this->metaConfigured() ? __('Configured') : __('Not configured') }}
                </x-filament::badge>
            </x-slot>

            <p class="fi-caption">{{ __('Lead Ads leadgen webhook events from the Meta Graph API.') }}</p>

            <form wire:submit="saveMeta" class="mt-4">
                {{ $this->metaForm }}

                <x-filament-panels::form.actions
                    :actions="[\Filament\Actions\Action::make('saveMeta')->label(__('Save'))->submit('saveMeta')]"
                />
            </form>
        </x-filament::section>
    </div>

    <x-filament::section heading="Test payload runner" class="mt-6">
        <form wire:submit="runTest">
            {{ $this->testRunnerForm }}

            <x-filament-panels::form.actions
                :actions="[\Filament\Actions\Action::make('runTest')->label(__('Run mapping preview'))->submit('runTest')]"
            />
        </form>

        @if ($this->testRunnerResult !== null)
            <div class="mt-4">
                <p class="fi-caption font-semibold">{{ __('Mapped result:') }}</p>

                @if (empty($this->testRunnerResult))
                    <p class="fi-caption">{{ __('No field in the payload matched a configured mapping rule.') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-gray-100 font-mono text-sm dark:divide-white/5">
                        @foreach ($this->testRunnerResult as $field => $value)
                            <li class="flex items-center justify-between gap-4 py-2">
                                <span class="text-gray-500 dark:text-gray-400">{{ $field }}</span>
                                <span class="text-gray-950 dark:text-white">{{ is_scalar($value) ? $value : json_encode($value) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
