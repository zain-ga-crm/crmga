<?php

namespace App\Filament\Resources\Concerns;

use App\Filament\RelationManagers\CallsRelationManager;
use App\Filament\RelationManagers\DocumentsRelationManager;
use App\Filament\RelationManagers\EmailsRelationManager;
use App\Filament\RelationManagers\MeetingsRelationManager;
use App\Filament\RelationManagers\NotesRelationManager;
use App\Filament\RelationManagers\TasksRelationManager;

/**
 * S-4.3 (relation-manager half): the create/edit companion to
 * ActivityTimelineWidget's read-only feed, on the same six Contactable
 * resources HasActivityTimelineFooter already puts the timeline widget on.
 * One shared list, since all six relation managers are reused as-is across
 * every resource -- they don't vary per module.
 */
trait HasActivityRelationManagers
{
    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            MeetingsRelationManager::class,
            TasksRelationManager::class,
            NotesRelationManager::class,
            DocumentsRelationManager::class,
            CallsRelationManager::class,
            EmailsRelationManager::class,
        ];
    }
}
