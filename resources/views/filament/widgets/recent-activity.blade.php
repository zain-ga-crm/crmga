<x-filament-widgets::widget>
    <x-filament::section heading="My recent activity">
        @php($records = $this->records())

        @if (empty($records))
            <p class="fi-in-placeholder text-sm text-gray-500 dark:text-gray-400">No recent activity.</p>
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
                            @if ($item['subjectLabel'])
                                @if ($item['url'])
                                    <a href="{{ $item['url'] }}" class="text-sm text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $item['subjectLabel'] }}
                                    </a>
                                @else
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $item['subjectLabel'] }}</p>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
