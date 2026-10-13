<?php

namespace App\Support\Etl;

use Illuminate\Support\Facades\DB;

/**
 * Z-6.2: the set of already-migrated local user ids, computed once and
 * shared across every transformer's nullableUserId() call within one
 * `crm:migrate-legacy` run (users migrate first -- load order position 1 --
 * so by the time anything else runs, this set is final).
 *
 * Deliberately ONE shared static here, not a per-transformer-class static
 * on the trait itself (an earlier version of this fix used that -- each of
 * the ~9 transformer classes using NormalizesLegacyValues got its OWN
 * static copy, which is fine for a real single CLI invocation but goes
 * stale across multiple `crm:migrate-legacy` calls within one PHP process,
 * exactly what the test suite does -- a user created in one test leaking
 * absent from a cache populated during an earlier test). One cache, one
 * reset() call, correct regardless of how many transformer classes ask.
 */
final class LegacyUserIdCache
{
    /** @var array<string, true>|null */
    private static ?array $ids = null;

    /**
     * @return array<string, true>
     */
    public static function ids(): array
    {
        if (self::$ids !== null) {
            return self::$ids;
        }

        $ids = [];
        foreach (DB::table('users')->pluck('id') as $id) {
            if (is_string($id)) {
                $ids[$id] = true;
            }
        }

        return self::$ids = $ids;
    }

    /**
     * Called at the top of `crm:migrate-legacy` so each run starts fresh --
     * matters for the test suite (many runs, one PHP process); a real CLI
     * invocation only ever runs once anyway.
     */
    public static function reset(): void
    {
        self::$ids = null;
    }
}
