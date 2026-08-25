<?php

namespace App\Support\Casts;

use BackedEnum;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts to a native backed enum on read without throwing when the stored value
 * falls outside the enum's hardcoded cases. The metadata option list, not the
 * enum, is the source of truth for these dropdowns (BACKEND_BRIEF §20 item 6) --
 * Studio can add a value the enum doesn't know about, or the ETL can carry over
 * a legacy value that was never mapped to a case. An unrecognized value passes
 * through as the raw string instead of null, so no data is hidden from readers.
 *
 * @implements CastsAttributes<BackedEnum|string|null, BackedEnum|string|null>
 */
final class SafeBackedEnumCast implements CastsAttributes
{
    /**
     * @param  class-string<BackedEnum>  $enumClass
     */
    public function __construct(private readonly string $enumClass) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): BackedEnum|string|null
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return $this->enumClass::tryFrom($value) ?? (string) $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value;
    }
}
