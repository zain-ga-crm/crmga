<x-filament-panels::page>
    <x-filament::section>
        <div class="max-w-sm">
            <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-950 dark:text-white" for="dnc-module">
                {{ __('Module') }}
            </label>

            <x-filament::input.wrapper>
                <x-filament::input.select id="dnc-module" wire:model.live="moduleKey">
                    @foreach ($this->moduleOptions() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
