<?php

namespace App\Filament\Exports;

use App\Filament\Exports\Concerns\BuildsExporterFromMetadata;
use Filament\Actions\Exports\Exporter;

/**
 * S-2.4: columns come from the 'leads' module's own 'list' layout -- see
 * BuildsExporterFromMetadata. getModel() resolves to App\Models\Lead by
 * this class's own name (Exporter's default convention), so nothing else
 * needs declaring here.
 */
class LeadExporter extends Exporter
{
    use BuildsExporterFromMetadata;

    public static function moduleKey(): string
    {
        return 'leads';
    }
}
