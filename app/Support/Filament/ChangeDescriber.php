<?php

namespace App\Support\Filament;

use App\Models\Metadata\Change;

/**
 * S-3.4: turns a Change row's kind + payload into the one plain-language sentence the
 * Change History screen shows per row. Pure formatting -- reads only what SchemaManager,
 * LayoutManager and OptionListManager already write to `payload`/`target_module`/
 * `target_field`; never queries anything else, so a change describes itself correctly
 * even if the module/field/list it touched has since been renamed or removed.
 */
final class ChangeDescriber
{
    public static function describe(Change $change): string
    {
        return match ($change->kind) {
            'field.add' => self::fieldAdd($change),
            'field.modify' => self::fieldModify($change),
            'field.delete' => self::fieldDelete($change),
            'layout.drafted' => self::layoutDrafted($change),
            'layout.published' => self::layoutPublished($change),
            'optionlist.created' => self::optionListCreated($change),
            'optionlist.renamed' => self::optionListRenamed($change),
            'option.added' => self::optionAdded($change),
            'option.updated' => self::optionUpdated($change),
            'option.removed' => self::optionRemoved($change),
            'option.reordered' => self::optionReordered($change),
            default => "[{$change->kind}] on [".self::target($change).'].',
        };
    }

    private static function fieldAdd(Change $change): string
    {
        $type = self::str(self::after($change)['type'] ?? null);

        return "Added field [{$change->target_field}] ({$type}) to [{$change->target_module}].";
    }

    private static function fieldModify(Change $change): string
    {
        $diff = self::diffSummary(self::before($change), self::after($change));

        return "Changed field [{$change->target_field}] on [{$change->target_module}]".
            ($diff === '' ? '.' : " ({$diff}).");
    }

    private static function fieldDelete(Change $change): string
    {
        return "Deleted field [{$change->target_field}] from [{$change->target_module}].";
    }

    private static function layoutDrafted(Change $change): string
    {
        $version = self::str(self::after($change)['version'] ?? null);

        return "Drafted version {$version} of the [{$change->target_field}] layout for [{$change->target_module}].";
    }

    private static function layoutPublished(Change $change): string
    {
        $version = self::str(self::after($change)['version'] ?? null);

        return "Published version {$version} of the [{$change->target_field}] layout for [{$change->target_module}].";
    }

    private static function optionListCreated(Change $change): string
    {
        $label = self::str(self::after($change)['label'] ?? null);

        return "Created option list [{$change->target_module}]".($label === '' ? '.' : " ({$label}).");
    }

    private static function optionListRenamed(Change $change): string
    {
        $from = self::str(self::before($change)['label'] ?? null);
        $to = self::str(self::after($change)['label'] ?? null);

        return "Renamed option list [{$change->target_module}] from [{$from}] to [{$to}].";
    }

    private static function optionAdded(Change $change): string
    {
        $label = self::str(self::after($change)['label'] ?? null);

        return "Added option [{$change->target_field}] ({$label}) to list [{$change->target_module}].";
    }

    private static function optionUpdated(Change $change): string
    {
        $diff = self::diffSummary(self::before($change), self::after($change));

        return "Updated option [{$change->target_field}] on list [{$change->target_module}]".
            ($diff === '' ? '.' : " ({$diff}).");
    }

    private static function optionRemoved(Change $change): string
    {
        return "Removed option [{$change->target_field}] from list [{$change->target_module}].";
    }

    private static function optionReordered(Change $change): string
    {
        return "Reordered the options on list [{$change->target_module}].";
    }

    /**
     * @return array<mixed, mixed>
     */
    private static function before(Change $change): array
    {
        $payload = $change->payload;
        $before = is_array($payload) ? ($payload['before'] ?? null) : null;

        return is_array($before) ? $before : [];
    }

    /**
     * @return array<mixed, mixed>
     */
    private static function after(Change $change): array
    {
        $payload = $change->payload;
        $after = is_array($payload) ? ($payload['after'] ?? null) : null;

        return is_array($after) ? $after : [];
    }

    private static function target(Change $change): string
    {
        return trim(($change->target_module ?? '').(($change->target_field ?? '') !== '' ? '/'.$change->target_field : ''), '/');
    }

    /**
     * @param  array<mixed, mixed>  $before
     * @param  array<mixed, mixed>  $after
     */
    private static function diffSummary(array $before, array $after): string
    {
        $lines = [];

        foreach ($after as $key => $newValue) {
            if (! is_string($key) || $key === 'validation') {
                continue;
            }

            $oldValue = $before[$key] ?? null;
            if ($newValue === $oldValue) {
                continue;
            }

            $lines[] = "{$key}: ".self::scalar($oldValue)." \u{2192} ".self::scalar($newValue);
        }

        return implode(', ', $lines);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => 'complex value',
        };
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
