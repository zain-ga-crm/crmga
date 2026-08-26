<?php

namespace App\Support\Ingest;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * §21 Q4: "Assignment rule for inbound leads (round-robin or by vertical) ->
 * Round-robin across active sales users." The candidate pool is every active user
 * holding a "Sales Representatives*" role (docs/reference/roles.php's naming
 * convention covers per-branch variants like "Sales Representatives - Dildar").
 * The last-assigned user id is persisted via Settings so the rotation survives
 * across requests/queue workers.
 */
final class LeadAssigner
{
    private const CURSOR_KEY = 'ingest.assignment.leads.last_user_id';

    private const LOCK_KEY = 'ingest.assignment.leads.lock';

    public function __construct(private readonly Settings $settings) {}

    public function assign(Model $record): void
    {
        try {
            $user = $this->nextUser();
        } catch (LockTimeoutException) {
            // Contention is expected to be rare (low ingest volume today) and a
            // stuck lead can be assigned manually — failing the whole ingest
            // request/job over a busy cursor would be a worse outcome than the
            // race this lock exists to close.
            Log::channel('api')->warning('lead_assignment_lock_timeout', ['record_id' => $record->getKey()]);

            return;
        }

        if ($user !== null) {
            $record->setAttribute('assigned_user_id', $user->id);
            $record->save();
        }
    }

    /**
     * Read-then-write on the cursor, so concurrent ingest processing (webhook
     * requests, queue workers) must be serialised here or two callers could read
     * the same cursor and double-assign the same user while skipping the next
     * one. block(5) waits up to 5s for the lock rather than failing immediately.
     */
    private function nextUser(): ?User
    {
        /** @var User|null $user Lock::block()'s return type is generic (mixed); this is the closure's own known return type, not an override of an inferred one. */
        $user = Cache::lock(self::LOCK_KEY, 10)->block(5, fn (): ?User => $this->nextUserUnderLock());

        return $user;
    }

    private function nextUserUnderLock(): ?User
    {
        $candidates = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('name', 'like', 'Sales Representatives%'))
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $lastId = $this->settings->get(self::CURSOR_KEY);
        $index = 0;

        if (is_string($lastId)) {
            $position = $candidates->search(fn (User $user): bool => $user->id === $lastId);
            if ($position !== false) {
                $index = ($position + 1) % $candidates->count();
            }
        }

        /** @var User $next */
        $next = $candidates[$index];
        $this->settings->set(self::CURSOR_KEY, $next->id);

        return $next;
    }
}
