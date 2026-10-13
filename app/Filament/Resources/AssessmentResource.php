<?php

namespace App\Filament\Resources;

use App\Filament\Exports\AssessmentExporter;
use App\Filament\Resources\AssessmentResource\Pages;
use App\Filament\Resources\Concerns\BuildsResourceFromMetadata;
use App\Models\Assessment;
use Filament\Actions\Exports\Exporter;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * S-4.2: the Express Entry CRS/FSW scorecard. Not one of the six Contactable
 * modules (see assessments' own migration comment), so unlike Lead/Company/
 * Student/Client/Affiliate/NewsletterSubscriber it does NOT get
 * HasActivityRelationManagers/HasActivityTimelineFooter -- an assessment is a
 * case-processing record against a Lead, not an independent contactable
 * entity. Everything here comes from the 'assessments' module's own
 * tenant_fields/tenant_layouts, same mechanism as every other DynamicResource.
 * The factor-by-factor score breakdown (the free-form `scores` json, ~40
 * legacy-named keys not registered as tenant_fields) is shown read-only by
 * AssessmentScorecardWidget on the view page, not through the metadata form.
 */
class AssessmentResource extends Resource
{
    use BuildsResourceFromMetadata;

    protected static ?string $model = Assessment::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Delivery';

    public static function moduleKey(): string
    {
        return 'assessments';
    }

    /**
     * @return class-string<Exporter>
     */
    public static function exporter(): ?string
    {
        return AssessmentExporter::class;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssessments::route('/'),
            'create' => Pages\CreateAssessment::route('/create'),
            'view' => Pages\ViewAssessment::route('/{record}'),
            'edit' => Pages\EditAssessment::route('/{record}/edit'),
        ];
    }
}
