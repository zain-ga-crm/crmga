<?php

namespace App\Filament\Widgets;

use App\Enums\LeadVertical;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use App\Support\DashboardService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * S-4.4 "hot leads" + "warm leads" widgets: one grouped bar chart rather than
 * two separate ones, since DashboardService::hotAndWarmByVertical() already
 * computes both counts in a single query per vertical (Z-4.3) -- splitting
 * the display back into two widgets would mean issuing it twice for no
 * benefit.
 */
class HotWarmByVerticalWidget extends ChartWidget
{
    protected static ?string $heading = 'Hot & warm leads by vertical';

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null
            && ($user->isAdmin() || app(Acl::class)->effective($user, 'leads', 'list') !== AccessLevel::None);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = app(DashboardService::class)->hotAndWarmByVertical();

        $labels = array_map(
            fn (array $row): string => LeadVertical::tryFrom($row['vertical'])?->label() ?? $row['vertical'],
            $rows,
        );

        return [
            'datasets' => [
                [
                    'label' => 'Hot',
                    'data' => array_map(fn (array $row): int => $row['hot'], $rows),
                    'backgroundColor' => '#ef4444',
                ],
                [
                    'label' => 'Warm',
                    'data' => array_map(fn (array $row): int => $row['warm'], $rows),
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => $labels,
        ];
    }
}
