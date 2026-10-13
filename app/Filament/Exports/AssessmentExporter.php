<?php

namespace App\Filament\Exports;

use App\Filament\Exports\Concerns\BuildsExporterFromMetadata;
use Filament\Actions\Exports\Exporter;

/**
 * S-4.2: columns come from the 'assessments' module's own 'list' layout --
 * see BuildsExporterFromMetadata. getModel() resolves to App\Models\Assessment
 * by this class's own name (Exporter's default convention).
 */
class AssessmentExporter extends Exporter
{
    use BuildsExporterFromMetadata;

    public static function moduleKey(): string
    {
        return 'assessments';
    }
}
