<?php

namespace App\Support;

use App\Models\Metadata\Change;
use App\Models\Metadata\Field;
use App\Models\Metadata\OptionItem;
use App\Models\Metadata\OptionList;
use App\Support\SchemaManager\ConcurrentSchemaChange;
use App\Support\SchemaManager\Snapshotter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persistence and validation for option lists and their items (Z-3.2). A system-frozen
 * list (BACKEND_BRIEF open question 9 — lead_vertical, lead_stage) never accepts item
 * changes here, regardless of confirm_lossy.
 */
final class OptionListManager
{
    public function __construct(
        private readonly Snapshotter $snapshotter,
        private readonly MetadataRepository $repository,
        private readonly FieldTypeContract $contract,
    ) {}

    /**
     * @return list<string> validation errors; empty means the key/label are acceptable
     */
    public function validateListKey(string $key): array
    {
        $errors = [];

        if (! preg_match('/'.$this->contract->fieldNamePattern().'/', $key)) {
            $errors[] = "Option list key [{$key}] does not match the required pattern.";
        }

        if (OptionList::query()->where('key', $key)->exists()) {
            $errors[] = "Option list [{$key}] already exists.";
        }

        return $errors;
    }

    public function createList(string $key, string $label, ?string $actorId = null): OptionList
    {
        $errors = $this->validateListKey($key);
        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        $list = OptionList::create(['key' => $key, 'label' => $label, 'is_system' => false]);

        Change::create([
            'actor_id' => $actorId,
            'kind' => 'optionlist.created',
            'target_module' => $key,
            'payload' => ['before' => null, 'after' => $list->only(['key', 'label'])],
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        $this->repository->bump();

        return $list;
    }

    public function renameList(string $listKey, string $newLabel, ?string $actorId = null): OptionList
    {
        $list = $this->findList($listKey);
        $errors = $this->guardEditable($list);
        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var OptionList $list */
        $before = $list->only(['label']);
        $list->update(['label' => $newLabel]);

        Change::create([
            'actor_id' => $actorId,
            'kind' => 'optionlist.renamed',
            'target_module' => $listKey,
            'payload' => ['before' => $before, 'after' => ['label' => $newLabel]],
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        $this->repository->bump();

        return $list;
    }

    public function addItem(
        string $listKey,
        string $value,
        string $label,
        ?int $sortOrder = null,
        ?string $color = null,
        bool $isActive = true,
        ?string $actorId = null,
    ): OptionItem {
        $list = $this->findList($listKey);
        $errors = $this->guardEditable($list);

        if ($list !== null && OptionItem::query()->where('option_list_id', $list->id)->where('value', $value)->exists()) {
            $errors[] = "Option [{$value}] already exists on list [{$listKey}].";
        }

        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var OptionList $list */
        $item = OptionItem::create([
            'option_list_id' => $list->id,
            'value' => $value,
            'label' => $label,
            'color' => $color,
            'is_active' => $isActive,
            'sort_order' => $sortOrder ?? ($this->maxSortOrder($list) + 1),
        ]);

        Change::create([
            'actor_id' => $actorId,
            'kind' => 'option.added',
            'target_module' => $listKey,
            'target_field' => $value,
            'payload' => ['before' => null, 'after' => $item->only(['value', 'label', 'color', 'is_active', 'sort_order'])],
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        $this->repository->bump();

        return $item;
    }

    /**
     * Renames an item's value and/or label/color/active flag. A value rename is the
     * lossy half: existing rows still store the OLD value, so it requires confirm_lossy
     * the same way removeItem() does, and -- unlike a plain label/color edit -- migrates
     * every affected row to the new value rather than leaving them orphaned.
     */
    public function updateItem(
        string $listKey,
        string $value,
        ?string $newValue = null,
        ?string $newLabel = null,
        ?string $newColor = null,
        ?bool $newIsActive = null,
        bool $confirmLossy = false,
        ?string $actorId = null,
    ): OptionItem {
        $list = $this->findList($listKey);
        $errors = $this->guardEditable($list);

        $item = $list === null ? null : OptionItem::query()
            ->where('option_list_id', $list->id)
            ->where('value', $value)
            ->first();

        if ($list !== null && $item === null) {
            $errors[] = "Option [{$value}] does not exist on list [{$listKey}].";
        }

        $targetValue = $newValue ?? $value;
        $valueChanging = $targetValue !== $value;

        if ($list !== null && $valueChanging
            && OptionItem::query()->where('option_list_id', $list->id)->where('value', $targetValue)->exists()) {
            $errors[] = "Option [{$targetValue}] already exists on list [{$listKey}].";
        }

        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var OptionList $list */
        /** @var OptionItem $item */
        $tables = $valueChanging ? $this->tablesUsing($list, $value) : [];

        if ($tables !== [] && ! $confirmLossy) {
            throw new MetadataValidationException([
                "Option [{$value}] is in use on [".implode(', ', $tables).'] and requires confirm_lossy to change its value.',
            ]);
        }

        $lock = Cache::lock('crm:schema', $this->configInt('schema-manager.lock_timeout_seconds', 10));
        if (! $lock->get()) {
            throw new ConcurrentSchemaChange('Another schema change is already in progress.');
        }

        try {
            $before = $item->only(['value', 'label', 'color', 'is_active']);
            $snapshotPath = $tables !== [] ? $this->snapshotter->snapshot($tables) : null;

            if ($valueChanging && $tables !== []) {
                $this->renameValueEverywhere($list, $value, $targetValue);
            }

            $item->update([
                'value' => $targetValue,
                'label' => $newLabel ?? $item->label,
                'color' => $newColor ?? $item->color,
                'is_active' => $newIsActive ?? $item->is_active,
            ]);

            Change::create([
                'actor_id' => $actorId,
                'kind' => 'option.updated',
                'target_module' => $listKey,
                'target_field' => $value,
                'payload' => ['before' => $before, 'after' => $item->only(['value', 'label', 'color', 'is_active']), 'affected_tables' => $tables],
                'status' => 'applied',
                'snapshot_path' => $snapshotPath,
                'applied_at' => now(),
            ]);

            $this->repository->bump();

            return $item;
        } finally {
            $lock->release();
        }
    }

    public function removeItem(string $listKey, string $value, bool $confirmLossy = false, ?string $actorId = null): void
    {
        $list = $this->findList($listKey);
        $errors = $this->guardEditable($list);

        $item = $list === null ? null : OptionItem::query()
            ->where('option_list_id', $list->id)
            ->where('value', $value)
            ->first();

        if ($list !== null && $item === null) {
            $errors[] = "Option [{$value}] does not exist on list [{$listKey}].";
        }

        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var OptionList $list */
        /** @var OptionItem $item */
        $tables = $this->tablesUsing($list, $value);

        if ($tables !== [] && ! $confirmLossy) {
            throw new MetadataValidationException([
                "Option [{$value}] is in use on [".implode(', ', $tables).'] and requires confirm_lossy to remove.',
            ]);
        }

        $lock = Cache::lock('crm:schema', $this->configInt('schema-manager.lock_timeout_seconds', 10));
        if (! $lock->get()) {
            throw new ConcurrentSchemaChange('Another schema change is already in progress.');
        }

        try {
            $before = $item->only(['value', 'label', 'sort_order']);
            $snapshotPath = $tables !== [] ? $this->snapshotter->snapshot($tables) : null;

            $item->delete();

            Change::create([
                'actor_id' => $actorId,
                'kind' => 'option.removed',
                'target_module' => $listKey,
                'target_field' => $value,
                'payload' => ['before' => $before, 'after' => null, 'affected_tables' => $tables],
                'status' => 'applied',
                'snapshot_path' => $snapshotPath,
                'applied_at' => now(),
            ]);

            $this->repository->bump();
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $orderedValues  every value currently on the list, in the new order
     */
    public function reorderItems(string $listKey, array $orderedValues, ?string $actorId = null): void
    {
        $list = $this->findList($listKey);
        $errors = $this->guardEditable($list);

        if ($errors !== []) {
            throw new MetadataValidationException($errors);
        }

        /** @var OptionList $list */
        $existing = OptionItem::query()->where('option_list_id', $list->id)->get()->keyBy('value');

        if ($existing->keys()->sort()->values()->all() !== collect($orderedValues)->sort()->values()->all()) {
            throw new MetadataValidationException(["The given values do not match the current items on list [{$listKey}] exactly."]);
        }

        $before = $existing->sortBy('sort_order')->keys()->values()->all();

        foreach ($orderedValues as $index => $value) {
            $existing->get($value)?->update(['sort_order' => $index]);
        }

        Change::create([
            'actor_id' => $actorId,
            'kind' => 'option.reordered',
            'target_module' => $listKey,
            'payload' => ['before' => $before, 'after' => $orderedValues],
            'status' => 'applied',
            'applied_at' => now(),
        ]);

        $this->repository->bump();
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Public wrapper around tablesUsing() so the Dropdown Editor (S-3.2) can show the
     * same used-by warning before either updateItem() (a value rename) or removeItem()
     * ever needs confirm_lossy.
     *
     * @return list<string>
     */
    public function usage(string $listKey, string $value): array
    {
        $list = $this->findList($listKey);

        return $list === null ? [] : $this->tablesUsing($list, $value);
    }

    private function findList(string $listKey): ?OptionList
    {
        return OptionList::query()->where('key', $listKey)->first();
    }

    /**
     * @return list<string>
     */
    private function guardEditable(?OptionList $list): array
    {
        if ($list === null) {
            return ['Option list does not exist.'];
        }

        if ($list->is_system) {
            return ["Option list [{$list->key}] is system-locked and cannot be changed."];
        }

        return [];
    }

    private function maxSortOrder(OptionList $list): int
    {
        $max = OptionItem::query()->where('option_list_id', $list->id)->max('sort_order');

        return is_numeric($max) ? (int) $max : -1;
    }

    /**
     * Every real table that stores this option list's value somewhere (enum: a plain
     * column match; multienum: a JSON-array containment match).
     *
     * @return list<string>
     */
    private function tablesUsing(OptionList $list, string $value): array
    {
        $tables = [];

        $fields = Field::query()->with('module')->where('option_list_id', $list->id)->get();

        foreach ($fields as $field) {
            $module = $field->module;
            if ($module === null || $module->table_name === null) {
                continue;
            }

            $table = $module->is_custom ? $module->table_name : $module->table_name.'_custom';
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $field->name)) {
                continue;
            }

            $exists = $field->type === 'multienum'
                ? DB::table($table)->whereJsonContains($field->name, $value)->exists()
                : DB::table($table)->where($field->name, $value)->exists();

            if ($exists) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Migrates every stored row from the old value to the new one, across every field
     * that uses this list -- called only after tablesUsing() has already gated the
     * caller on confirm_lossy, and only once a snapshot of $tables has been taken.
     * Multienum columns are JSON arrays, so each matching row is rewritten in PHP
     * (fetch, replace the one element, re-encode) rather than a single SQL UPDATE.
     */
    private function renameValueEverywhere(OptionList $list, string $oldValue, string $newValue): void
    {
        $fields = Field::query()->with('module')->where('option_list_id', $list->id)->get();

        foreach ($fields as $field) {
            $module = $field->module;
            if ($module === null || $module->table_name === null) {
                continue;
            }

            $table = $module->is_custom ? $module->table_name : $module->table_name.'_custom';
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $field->name)) {
                continue;
            }

            if ($field->type === 'multienum') {
                foreach (DB::table($table)->whereJsonContains($field->name, $oldValue)->get(['id', $field->name]) as $row) {
                    $raw = $row->{$field->name};
                    $values = is_string($raw) ? json_decode($raw, true) : null;
                    if (! is_array($values)) {
                        continue;
                    }

                    $values = array_values(array_map(
                        fn (mixed $v): mixed => $v === $oldValue ? $newValue : $v,
                        $values,
                    ));

                    DB::table($table)->where('id', $row->id)->update([$field->name => json_encode($values)]);
                }

                continue;
            }

            DB::table($table)->where($field->name, $oldValue)->update([$field->name => $newValue]);
        }
    }
}
