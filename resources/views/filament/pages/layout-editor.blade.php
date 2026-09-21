<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-col gap-4 sm:flex-row">
            <div class="max-w-sm flex-1">
                <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-950 dark:text-white" for="layout-editor-module">
                    {{ __('Module') }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="layout-editor-module" wire:model.live="moduleKey">
                        @foreach ($this->moduleOptions() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            <div class="max-w-sm flex-1">
                <label class="fi-fo-field-wrp-label mb-1 block text-sm font-medium text-gray-950 dark:text-white" for="layout-editor-view">
                    {{ __('View') }}
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="layout-editor-view" wire:model.live="viewName">
                        @foreach ($this->viewOptions() as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
        </div>
    </x-filament::section>

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    <x-filament::section heading="Preview" description="A schematic preview of the current, unsaved layout -- field order, panels/tabs and widths, not live record data.">
        @if ($this->isColumnsView())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left dark:border-white/10">
                            <th class="py-1 pe-4">{{ __('Column') }}</th>
                            <th class="py-1 pe-4">{{ __('Field') }}</th>
                            <th class="py-1 pe-4">{{ __('Width') }}</th>
                            <th class="py-1">{{ __('Priority') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->previewRows() as $column)
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-1 pe-4">{{ $column['label'] }}</td>
                                <td class="py-1 pe-4 font-mono text-xs text-gray-500">{{ $column['field'] }}</td>
                                <td class="py-1 pe-4">{{ $column['width'] ?? 'auto' }}</td>
                                <td class="py-1">{{ $column['priority'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-2 text-gray-500">{{ __('No columns yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="space-y-4">
                @forelse ($this->previewRows() as $panel)
                    <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                        <div class="mb-2 flex items-center gap-2">
                            <span class="font-medium">{{ $panel['label'] }}</span>
                            @if ($panel['tab'])
                                <x-filament::badge color="gray" size="sm">{{ $panel['tab'] }}</x-filament::badge>
                            @endif
                        </div>
                        <div class="space-y-1">
                            @forelse ($panel['rows'] as $row)
                                <div class="grid gap-2" style="grid-template-columns: repeat({{ count($row) }}, 1fr);">
                                    @foreach ($row as $slot)
                                        <div class="rounded bg-gray-50 px-2 py-1 text-xs dark:bg-white/5">
                                            {{ $slot['label'] }}
                                            <span class="text-gray-400">({{ $slot['field'] }})</span>
                                        </div>
                                    @endforeach
                                </div>
                            @empty
                                <p class="text-sm text-gray-500">{{ __('No fields yet.') }}</p>
                            @endforelse
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">{{ __('No panels yet.') }}</p>
                @endforelse
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Version history">
        <div class="space-y-2">
            @forelse ($this->versions() as $version)
                <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ __('Version') }} {{ $version->version }}</span>
                        @if ($version->is_published)
                            <x-filament::badge color="success" size="sm">{{ __('Published') }}</x-filament::badge>
                        @endif
                        <span class="text-sm text-gray-500">{{ $version->created_at?->diffForHumans() }}</span>
                    </div>
                    @unless ($version->is_published)
                        <x-filament::button
                            color="gray"
                            size="sm"
                            wire:click="revertToVersion('{{ $version->id }}')"
                            wire:confirm="{{ __('Revert to version :version and publish it immediately?', ['version' => $version->version]) }}"
                        >
                            {{ __('Revert & publish') }}
                        </x-filament::button>
                    @endunless
                </div>
            @empty
                <p class="text-sm text-gray-500">{{ __('No versions saved yet.') }}</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
