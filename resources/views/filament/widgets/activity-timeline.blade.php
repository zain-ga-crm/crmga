<x-filament-widgets::widget>
    <x-filament::section heading="Activity timeline">
        @php($records = $this->records())

        @if (empty($records))
            <p class="fi-in-placeholder text-sm text-gray-500 dark:text-gray-400">No activity yet.</p>
        @else
            <ul class="space-y-3">
                @foreach ($records as $item)
                    <li class="flex items-start gap-3">
                        <x-filament::icon :icon="$item['icon']" class="mt-0.5 h-5 w-5 shrink-0 text-gray-400" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">
                                    <span class="text-gray-500 dark:text-gray-400">{{ $item['type'] }}:</span>
                                    {{ $item['title'] }}
                                </p>
                                <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $item['when']->diffForHumans() }}
                                </span>
                            </div>
                            @if ($item['summary'])
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $item['summary'] }}</p>
                            @endif
                            <p class="text-xs text-gray-400 dark:text-gray-500">{{ $item['who'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
