<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\LeadResource;
use App\Support\Acl;
use App\Support\Acl\AccessLevel;
use App\Support\DashboardService;
use Filament\Facades\Filament;
use Filament\Resources\Resource as FilamentResource;
use Filament\Widgets\Widget;

/**
 * "Attention needed" spans two models (overdue Leads and overdue Clients,
 * per DashboardService::attentionNeeded()'s Z-4.3 definition) -- a Filament
 * table widget binds to one Eloquent query, so a plain list widget over the
 * already-merged/sorted array is simpler than forcing two models through one
 * Builder.
 */
class AttentionNeededWidget extends Widget
{
    protected static string $view = 'filament.widgets.attention-needed';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user !== null
            && ($user->isAdmin()
                || app(Acl::class)->effective($user, 'leads', 'list') !== AccessLevel::None
                || app(Acl::class)->effective($user, 'clients', 'list') !== AccessLevel::None);
    }

    /**
     * @return array<int, array{module: string, id: string, full_name: string, due_at: string, url: string}>
     */
    public function records(): array
    {
        $resources = [
            'leads' => LeadResource::class,
            'clients' => ClientResource::class,
        ];

        return array_map(
            /** @param array{module: string, id: string, full_name: string, due_at: string} $row */
            function (array $row) use ($resources): array {
                /** @var class-string<FilamentResource> $resource */
                $resource = $resources[$row['module']];

                return [...$row, 'url' => $resource::getUrl('view', ['record' => $row['id']])];
            },
            app(DashboardService::class)->attentionNeeded(),
        );
    }
}
