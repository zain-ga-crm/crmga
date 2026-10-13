<?php

namespace App\Filament\Widgets;

use App\Models\Assessment;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;

/**
 * S-4.2: the CRS/FSW scorecard's "score summary" and "factor-by-factor
 * breakdown" halves. Read-only by design -- PROJECT_PLAN.md §6's pre-agreed
 * cut order explicitly allows "Assessment scorecard becomes read-only, with
 * scores calculated on import", and that is exactly what the data already
 * is: AssessmentScoreTransformer computes crs_score/fsw_score and the
 * `scores` factor breakdown once, during ETL, from already-submitted legacy
 * applications. There is no live CRS/FSW rule engine in this codebase to
 * re-derive a score from, so a "stepped entry form" that edited raw factors
 * would have nothing authoritative to recalculate against -- building one
 * would mean inventing Canadian immigration point-scoring rules, which
 * belongs in IRCC's own calculator, not here.
 *
 * `scores` keys are the original legacy column names (see
 * AssessmentScoreTransformer::SCORE_COLUMNS) -- not registered as
 * tenant_fields, so they're humanised for display here rather than going
 * through FieldTypeRegistry.
 */
class AssessmentScorecardWidget extends Widget
{
    protected static string $view = 'filament.widgets.assessment-scorecard';

    // Record-scoped, only ever used via ViewAssessment's getFooterWidgets()
    // -- without this, discoverWidgets() also registers it panel-wide on the
    // general Dashboard, where it has no $record (same gotcha as
    // ActivityTimelineWidget, see its own docblock).
    protected static bool $isDiscovered = false;

    public ?Assessment $record = null;

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function summary(): array
    {
        if ($this->record === null) {
            return [];
        }

        $record = $this->record;

        return [
            ['label' => 'Case type', 'value' => Str::upper($record->case_type)],
            ['label' => 'Status', 'value' => Str::headline($record->status)],
            ['label' => 'CRS score', 'value' => $record->crs_score === null ? '—' : (string) $record->crs_score],
            ['label' => 'FSW score', 'value' => $record->fsw_score === null ? '—' : (string) $record->fsw_score],
            ['label' => 'Assessed at', 'value' => $record->assessed_at?->format('Y-m-d H:i') ?? '—'],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function factors(): array
    {
        if ($this->record === null) {
            return [];
        }

        $scores = $this->record->scores ?? [];

        return collect($scores)
            ->reject(fn (mixed $value, string $key): bool => $key === 'legacy_status')
            ->map(fn (mixed $value, string $key): array => [
                'label' => Str::headline($key),
                'value' => is_scalar($value) ? (string) $value : '—',
            ])
            ->sortBy('label')
            ->values()
            ->all();
    }
}
