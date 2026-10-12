<?php

namespace App\Filament\Widgets;

use App\Models\Call;
use App\Models\Document;
use App\Models\Email;
use App\Models\Meeting;
use App\Models\Note;
use App\Models\Task;
use App\Support\Filament\ActivityFeedFormatter;
use App\Support\Filament\ContactableModuleRegistry;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * S-4.4's 8th widget, deferred at the time (blocked on S-4.3's activity
 * feed, now built). "Recent activity" here means MY recent activity --
 * same reasoning as MyTasksWidget/TodaysMeetingsWidget: none of the six
 * HasActivities models carry a module-level ACL of their own, so scoping
 * to assigned_user_id is the access rule, not a per-row ACL check against
 * six different subject types.
 */
class RecentActivityWidget extends Widget
{
    protected static string $view = 'filament.widgets.recent-activity';

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 1;

    private const LIMIT = 10;

    /**
     * @return array<int, array{type: string, icon: string, title: string, summary: string|null, who: string, when: Carbon, subjectLabel: string|null, url: string|null}>
     */
    public function records(): array
    {
        $userId = Filament::auth()->id();
        if (! is_string($userId)) {
            return [];
        }

        $items = Meeting::query()->with('subject')->where('assigned_user_id', $userId)->latest()->limit(self::LIMIT)->get()
            ->concat(Note::query()->with('subject')->where('assigned_user_id', $userId)->latest()->limit(self::LIMIT)->get())
            ->concat(Document::query()->with('subject')->where('assigned_user_id', $userId)->latest()->limit(self::LIMIT)->get())
            ->concat(Call::query()->with('subject')->where('assigned_user_id', $userId)->latest()->limit(self::LIMIT)->get())
            ->concat(Task::query()->with('subject')->where('assigned_user_id', $userId)->latest()->limit(self::LIMIT)->get())
            ->concat(Email::query()->with('subject')->where('assigned_user_id', $userId)->latest()->limit(self::LIMIT)->get());

        return $items
            ->sortByDesc('created_at')
            ->take(self::LIMIT)
            ->map(function (Meeting|Note|Document|Call|Task|Email $item): array {
                $row = ActivityFeedFormatter::describe($item);
                $subject = $item->subject;

                return [
                    ...$row,
                    'subjectLabel' => $this->subjectLabel($subject),
                    'url' => $this->subjectUrl($subject),
                ];
            })
            ->values()
            ->all();
    }

    private function subjectLabel(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        $name = ContactableModuleRegistry::fullNameOf($subject);

        return $name !== '' ? $name : null;
    }

    private function subjectUrl(?Model $subject): ?string
    {
        if ($subject === null) {
            return null;
        }

        $resource = ContactableModuleRegistry::resourceFor($subject);

        return $resource !== null ? $resource::getUrl('view', ['record' => $subject]) : null;
    }
}
