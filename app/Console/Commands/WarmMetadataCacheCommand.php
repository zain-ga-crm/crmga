<?php

namespace App\Console\Commands;

use App\Support\MetadataRepository;
use Illuminate\Console\Command;

/**
 * §11: "Metadata cache warm -- on deploy." MetadataRepository::compiled()
 * already caches forever (invalidated by its own version bump, not by TTL),
 * but that cache is empty right after a fresh deploy -- whichever request
 * happens to arrive first pays the full compile cost instead of an
 * administrator. A deploy script runs this once so that request never has to.
 */
final class WarmMetadataCacheCommand extends Command
{
    protected $signature = 'crm:metadata:warm';

    protected $description = 'Warm the compiled metadata cache so the first request after a deploy isn\'t the one paying for it (BACKEND_BRIEF §11)';

    public function handle(MetadataRepository $repository): int
    {
        $repository->compiled();

        $this->info('Metadata cache warmed.');

        return self::SUCCESS;
    }
}
