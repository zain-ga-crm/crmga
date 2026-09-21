<?php

namespace App\Support\Filament;

use App\Models\Call;
use App\Models\Document;
use App\Models\Email;
use App\Models\Meeting;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Models\Audit;

/**
 * S-4.3: turns one of the six HasActivities models (Meeting/Note/Document/
 * Call/Task/Email) or one laravel-auditing Audit row into the one common
 * shape ActivityTimelineWidget's view renders -- the same "normalise many
 * kinds into one display row" job ChangeDescriber does for Studio's change
 * log.
 *
 * "Field changes" reads from laravel-auditing's already-populated `audits`
 * table (every CRM model already implements Auditable per
 * CrmAuditingTest.php), not App\Models\Metadata\Change -- that model logs
 * Studio's own schema/metadata edits, not a record's data edits.
 */
final class ActivityFeedFormatter
{
    /**
     * @return array{type: string, icon: string, title: string, summary: string|null, who: string, when: Carbon}
     */
    public static function describe(Meeting|Note|Document|Call|Task|Email|Audit $item): array
    {
        return match (true) {
            $item instanceof Meeting => self::row(
                'Meeting',
                'heroicon-o-calendar',
                $item->name,
                trim(($item->location ?? '').' '.($item->status !== '' ? "({$item->status})" : '')) ?: null,
                self::activityWho($item->createdBy?->name, $item->assignedUser?->name),
                $item->date_start,
            ),
            $item instanceof Note => self::row(
                'Note',
                'heroicon-o-pencil-square',
                $item->name ?? 'Note',
                self::truncate($item->body),
                self::activityWho($item->createdBy?->name, $item->assignedUser?->name),
                $item->created_at,
            ),
            $item instanceof Document => self::row(
                'Document',
                'heroicon-o-paper-clip',
                $item->name,
                $item->category,
                self::activityWho($item->createdBy?->name, $item->assignedUser?->name),
                $item->created_at,
            ),
            $item instanceof Call => self::row(
                'Call',
                'heroicon-o-phone',
                ucfirst($item->direction).' call'.($item->outcome !== null ? " — {$item->outcome}" : ''),
                self::truncate($item->summary),
                self::activityWho($item->createdBy?->name, $item->assignedUser?->name),
                $item->date_start,
            ),
            $item instanceof Task => self::row(
                'Task',
                'heroicon-o-check-circle',
                $item->name,
                "{$item->priority} priority, {$item->status}",
                self::activityWho($item->createdBy?->name, $item->assignedUser?->name),
                $item->created_at,
            ),
            $item instanceof Email => self::row(
                'Email',
                'heroicon-o-envelope',
                $item->subject_line ?? '(no subject)',
                self::truncate($item->body_text),
                self::activityWho($item->createdBy?->name, $item->assignedUser?->name),
                $item->sent_at ?? $item->created_at,
            ),
            $item instanceof Audit => self::row(
                'Field change',
                'heroicon-o-clock',
                ucfirst(self::str($item->getAttribute('event'))).': '.implode(', ', array_keys((array) $item->getModified())),
                null,
                self::auditWho($item),
                $item->getAttribute('created_at'),
            ),
        };
    }

    /**
     * @return array{type: string, icon: string, title: string, summary: string|null, who: string, when: Carbon}
     */
    private static function row(string $type, string $icon, string $title, ?string $summary, string $who, mixed $when): array
    {
        return [
            'type' => $type,
            'icon' => $icon,
            'title' => $title,
            'summary' => $summary,
            'who' => $who,
            'when' => self::toCarbon($when),
        ];
    }

    private static function toCarbon(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        return is_string($value) ? Carbon::parse($value) : Carbon::now();
    }

    private static function activityWho(?string $createdBy, ?string $assignedTo): string
    {
        return $createdBy ?? $assignedTo ?? 'System';
    }

    private static function auditWho(Audit $audit): string
    {
        $userType = $audit->getAttribute('user_type');
        $userId = $audit->getAttribute('user_id');

        if ($userType === User::class && is_string($userId)) {
            $user = User::query()->firstWhere('id', $userId);

            return $user !== null ? $user->name : 'System';
        }

        return 'System';
    }

    private static function truncate(?string $text, int $length = 140): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return str($text)->stripTags()->limit($length)->toString();
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
