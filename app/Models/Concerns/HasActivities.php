<?php

namespace App\Models\Concerns;

use App\Models\Call;
use App\Models\Document;
use App\Models\Email;
use App\Models\Meeting;
use App\Models\Note;
use App\Models\Task;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * The record side of the polymorphic activities (BACKEND_BRIEF §7.3) — any
 * Contactable (or other CRM) model can carry meetings, notes, documents,
 * calls, tasks and emails without its own link table.
 */
trait HasActivities
{
    /**
     * Eager-loads createdBy/assignedUser on every relation below -- Z-4.4:
     * ActivityFeedFormatter::describe() reads both on every row of
     * activityFeed(), and without this, every activity item on a record's
     * timeline triggers two extra queries of its own.
     *
     * @var list<string>
     */
    private const ACTIVITY_EAGER_LOAD = ['createdBy', 'assignedUser'];

    /** @return MorphMany<Meeting, $this> */
    public function meetings(): MorphMany
    {
        return $this->morphMany(Meeting::class, 'subject')->with(self::ACTIVITY_EAGER_LOAD);
    }

    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'subject')->with(self::ACTIVITY_EAGER_LOAD);
    }

    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'subject')->with(self::ACTIVITY_EAGER_LOAD);
    }

    /** @return MorphMany<Call, $this> */
    public function calls(): MorphMany
    {
        return $this->morphMany(Call::class, 'subject')->with(self::ACTIVITY_EAGER_LOAD);
    }

    /** @return MorphMany<Task, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'subject')->with(self::ACTIVITY_EAGER_LOAD);
    }

    /** @return MorphMany<Email, $this> */
    public function emails(): MorphMany
    {
        return $this->morphMany(Email::class, 'subject')->with(self::ACTIVITY_EAGER_LOAD);
    }

    /**
     * Every activity for this record, newest first — the raw feed a
     * paginated timeline UI groups and filters by type.
     *
     * @return Collection<int, Meeting|Note|Document|Call|Task|Email>
     */
    public function activityFeed(): Collection
    {
        return $this->meetings
            ->concat($this->notes)
            ->concat($this->documents)
            ->concat($this->calls)
            ->concat($this->tasks)
            ->concat($this->emails)
            ->sortByDesc('created_at')
            ->values();
    }
}
