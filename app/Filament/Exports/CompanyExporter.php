<?php

namespace App\Filament\Exports;

use App\Filament\Exports\Concerns\BuildsExporterFromMetadata;
use Filament\Actions\Exports\Exporter;

/**
 * S-2.4: columns come from the 'companies' module's own 'list' layout -- see
 * BuildsExporterFromMetadata. getModel() resolves to App\Models\Company by
 * this class's own name (Exporter's default convention), so nothing else
 * needs declaring here.
 */
class CompanyExporter extends Exporter
{
    use BuildsExporterFromMetadata;

    public static function moduleKey(): string
    {
        return 'companies';
    }
}
