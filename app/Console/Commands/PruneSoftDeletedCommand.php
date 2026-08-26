<?php

namespace App\Console\Commands;

use App\Support\Api\ApiModuleRegistry;
use Illuminate\Console\Command;

/**
 * §21 Q6's own open-question default: "retention period before a soft-deleted
 * record can be hard-deleted -> 90 days." Nothing implemented that, or scheduled
 * it -- and this stays that way here too: `--force` is required to actually
 * delete anything, and nothing in routes/console.php calls this command, so it
 * only ever runs when an operator runs it by hand. Once it's been run and
 * reviewed a few times, scheduling it (like the existing schema-manager
 * snapshot cleanup job) is a follow-up, not part of this change.
 */
final class PruneSoftDeletedCommand extends Command
{
    protected $signature = 'crm:prune-soft-deleted {--force : Actually hard-delete; without this, only reports what would be deleted}';

    protected $description = 'Hard-delete soft-deleted records past the retention window (§21 Q6, default 90 days)';

    private const CHUNK_SIZE = 500;

    public function handle(ApiModuleRegistry $registry): int
    {
        $days = $this->configInt('records.soft_delete_retention_days', 90);
        $cutoff = now()->subDays($days);
        $force = (bool) $this->option('force');

        $rows = [];
        $total = 0;

        foreach ($registry->moduleKeys() as $key) {
            $modelClass = $registry->modelFor($key);

            // withoutGlobalScopes() (no args) removes every registered global
            // scope, including SoftDeletingScope -- same effect as withTrashed(),
            // without relying on a trait method a plain class-string<Model> type
            // can't statically guarantee every model has.
            $count = $modelClass::withoutGlobalScopes()
                ->whereNotNull('deleted_at')
                ->where('deleted_at', '<', $cutoff)
                ->count();

            if ($force && $count > 0) {
                $modelClass::withoutGlobalScopes()
                    ->whereNotNull('deleted_at')
                    ->where('deleted_at', '<', $cutoff)
                    ->chunkById(self::CHUNK_SIZE, function ($records): void {
                        foreach ($records as $record) {
                            $record->forceDelete();
                        }
                    });
            }

            $rows[] = [$key, $count];
            $total += $count;
        }

        $this->table(['Module', $force ? 'Purged' : 'Would purge (pass --force to delete)'], $rows);

        if ($total === 0) {
            $this->info("Nothing older than {$days} days is soft-deleted -- nothing to do.");
        } elseif (! $force) {
            $this->warn("{$total} record(s) across ".count($registry->moduleKeys())." module(s) are past the {$days}-day retention window. Re-run with --force to hard-delete them.");
        } else {
            $this->info("Hard-deleted {$total} record(s) past the {$days}-day retention window.");
        }

        return self::SUCCESS;
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key);

        return is_numeric($value) ? (int) $value : $default;
    }
}
