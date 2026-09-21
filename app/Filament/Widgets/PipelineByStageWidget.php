<?php

namespace App\Filament\Widgets;

use App\Enums\LeadStage;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use App\Support\DashboardService;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class PipelineByStageWidget extends ChartWidget
{
    protected static ?string $heading = 'Pipeline by stage';

    protected static ?int $sort = 2;

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
        $rows = app(DashboardService::class)->pipelineByStage();

        return [
            'datasets' => [
                [
                    'label' => 'Leads',
                    'data' => array_map(fn (array $row): int => $row['count'], $rows),
                    'backgroundColor' => '#3b82f6',
                ],
            ],
            'labels' => array_map(
                fn (array $row): string => LeadStage::tryFrom($row['stage'])?->label() ?? $row['stage'],
                $rows,
            ),
        ];
    }
}
