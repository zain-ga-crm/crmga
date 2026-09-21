<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-col gap-4 sm:flex-row">
            <div class="max-w-sm flex-1">
                <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-950 dark:text-white" for="hot-warm-module">
                    {{ __('Module') }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="hot-warm-module" wire:model.live="moduleKey">
                        @foreach ($this->moduleOptions() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div class="max-w-sm flex-1">
                <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-950 dark:text-white" for="hot-warm-temperature">
                    {{ __('Temperature') }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="hot-warm-temperature" wire:model.live="temperature">
                        @foreach ($this->temperatureOptions() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
