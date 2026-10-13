<?php

namespace App\Support\Etl\Concerns;

use App\Support\Etl\LegacyUserIdCache;

/**
 * Small value-coercion helpers shared by every LegacyTransformer — SuiteCRM
 * columns are untyped strings at the PHP layer regardless of their declared SQL
 * type, so every transformer needs the same "is this really absent" and
 * "is this really a number" checks.
 */
trait NormalizesLegacyValues
{
    private function stringValue(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return $string === '' ? null : $string;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Guards a target column's own length limit against free-text legacy
     * garbage in a field that's typed for something much shorter (e.g. a
     * "phone" column holding someone's name and credentials instead) --
     * one bad value truncating is far better than it failing the whole
     * row's insert outright (found via a `contact_person_phone` value 5
     * characters over its 50-char column during the Z-6.2 companies
     * reconciliation gap).
     */
    private function nullableTruncated(mixed $value, int $maxLength): ?string
    {
        $string = $this->nullableString($value);

        return $string !== null ? mb_substr($string, 0, $maxLength) : null;
    }

    /**
     * A handful of legacy `created_by`/`modified_user_id`-style FK columns hold
     * free text ("Prince Saha", an email address) instead of a real user id —
     * a data-quality defect, not a real reference. Treat anything that isn't
     * UUID-shaped as absent rather than letting it hit the target's foreign
     * key constraint and fail the whole row.
     */
    private function nullableUuid(mixed $value): ?string
    {
        $string = $this->nullableString($value);
        if ($string === null) {
            return null;
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string) === 1
            ? $string
            : null;
    }

    /**
     * Z-6.2 fix: `nullableUuid()` only guards the FK column's SHAPE, not
     * whether it points at a real row -- a well-formed UUID whose legacy
     * `users` row was never recovered (deleted outright in the source,
     * never just soft-deleted) still fails `assigned_user_id`/`created_by`/
     * `modified_by`'s real FK constraint at save() time, which kills the
     * ENTIRE parent row's migration over one unresolvable reference (found
     * via 78 FK failures on assessments_score alone, all tracing to legacy
     * user ids that genuinely don't exist in the legacy `users` table --
     * confirmed per-id, not guessed). See LegacyUserIdCache for why this
     * is one shared cache, not a static on this trait.
     */
    private function nullableUserId(mixed $value): ?string
    {
        $uuid = $this->nullableUuid($value);

        return ($uuid !== null && isset(LegacyUserIdCache::ids()[$uuid])) ? $uuid : null;
    }

    /**
     * Several `_cstm` "boolean" columns are actually varchar, not tinyint — an
     * empty string or a literal "0"/"false"/"no" (case-insensitive) is false,
     * anything else non-empty is true.
     */
    private function legacyBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $string = strtolower($this->stringValue($value));

        return $string !== '' && ! in_array($string, ['0', 'false', 'no'], true);
    }
}
