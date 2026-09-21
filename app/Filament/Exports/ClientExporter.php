<?php

namespace App\Filament\Exports;

use App\Filament\Exports\Concerns\BuildsExporterFromMetadata;
use Filament\Actions\Exports\Exporter;

/**
 * S-4.1: columns come from the 'clients' module's own 'list' layout -- see
 * BuildsExporterFromMetadata.
 */
class ClientExporter extends Exporter
{
    use BuildsExporterFromMetadata;

    public static function moduleKey(): string
    {
        return 'clients';
    }
}
