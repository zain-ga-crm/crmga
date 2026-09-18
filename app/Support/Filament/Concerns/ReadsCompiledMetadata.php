<?php

namespace App\Support\Filament\Concerns;

use App\Support\MetadataRepository;
use RuntimeException;

/**
 * Shared by BuildsResourceFromMetadata and BuildsExporterFromMetadata: both
 * need the same compiled-module lookup and the same type-narrowing around
 * MetadataRepository::compiled()'s loosely-typed array<string, mixed> shape.
 * One copy of this coercion, not two that could quietly drift apart.
 */
trait ReadsCompiledMetadata
{
    abstract public static function moduleKey(): string;

    /**
     * @return array<string, mixed>
     */
    private static function compiledModule(): array
    {
        $compiled = app(MetadataRepository::class)->compiled();
        $modules = self::assoc($compiled['modules'] ?? null);
        $key = self::moduleKey();

        $module = $modules[$key] ?? null;
        if (! is_array($module)) {
            throw new RuntimeException("Module [{$key}] is not registered in the metadata registry.");
        }

        return self::assoc($module);
    }

    /**
     * @param  array<string, mixed>  $module
     * @return array<string, array<string, mixed>>
     */
    private static function fieldsMap(array $module): array
    {
        $fields = self::assoc($module['fields'] ?? null);
        $typed = [];
        foreach ($fields as $name => $meta) {
            if (is_array($meta)) {
                $typed[$name] = self::assoc($meta);
            }
        }

        return $typed;
    }

    /**
     * Every object-shaped piece of the layout schema (a panel, a slot,
     * "content" itself) is JSON-decoded into a string-keyed array; this is
     * what lets a caller safely treat it as array<string, mixed>.
     *
     * @return array<string, mixed>
     */
    private static function assoc(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * Like assoc(), but for the layout schema's own list-of-*object* shapes
     * (panels, tabs, columns, and a row's own slots) -- drops any element
     * that isn't itself an object and reindexes.
     *
     * @return list<array<string, mixed>>
     */
    private static function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $out[] = self::assoc($item);
            }
        }

        return $out;
    }

    private static function str(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }
}
