<?php

namespace App\Filament\Exports\Concerns;

use App\Support\Filament\Concerns\ReadsCompiledMetadata;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;

/**
 * S-2.4: the export half of DynamicResource -- an Exporter's columns come
 * from the same 'list' layout the table itself uses (tenant_layouts +
 * tenant_fields), so a module's export always matches what's actually on
 * screen without a second, hand-maintained column list. A concrete Exporter
 * (LeadExporter, CompanyExporter) just declares moduleKey(); this trait does
 * the rest.
 *
 * Deliberately NOT field-level-ACL-aware: Filament's Export runs as a queued
 * job with no per-row-authorized request context by the time it executes,
 * and BulkExportAction/ExportAction are themselves gated on the module's
 * 'export' action in BuildsResourceFromMetadata -- a user with export access
 * gets the same columns as the table shows an admin, not a per-viewer subset.
 * If a field genuinely must never leave the system, that's a field the
 * export can't allow at all, not just hide -- a coarser control not built
 * here since no such field exists yet.
 */
trait BuildsExporterFromMetadata
{
    use ReadsCompiledMetadata;

    /**
     * @return array<ExportColumn>
     */
    public static function getColumns(): array
    {
        $module = self::compiledModule();
        $layout = self::assoc($module['layouts'] ?? null)['list'] ?? null;

        if (! is_array($layout)) {
            return [];
        }

        $fields = self::fieldsMap($module);
        $content = self::assoc($layout['content'] ?? null);

        $columns = [];
        foreach (self::listOfArrays($content['columns'] ?? null) as $columnDef) {
            $name = self::str($columnDef['field'] ?? null);
            if ($name === '') {
                continue;
            }

            if ($name === 'assigned_user_id') {
                $columns[] = ExportColumn::make('assignedUser.name')->label('Owner');

                continue;
            }

            if (! isset($fields[$name])) {
                continue;
            }

            $label = $columnDef['label'] ?? $fields[$name]['label'] ?? null;
            $column = ExportColumn::make($name);
            if (is_string($label) && $label !== '') {
                $column = $column->label($label);
            }

            $columns[] = $column;
        }

        return $columns;
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $module = self::compiledModule();
        $label = self::str($module['label_plural'] ?? null, self::moduleKey());
        $successCount = number_format($export->successful_rows);

        $body = "Your {$label} export has completed and {$successCount} ".str('row')->plural($export->successful_rows).' exported.';

        $failedCount = $export->getFailedRowsCount();
        if ($failedCount > 0) {
            $body .= ' '.number_format($failedCount).' '.str('row')->plural($failedCount).' failed to export.';
        }

        return $body;
    }
}
