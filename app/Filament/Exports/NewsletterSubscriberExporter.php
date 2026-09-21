<?php

namespace App\Filament\Exports;

use App\Filament\Exports\Concerns\BuildsExporterFromMetadata;
use Filament\Actions\Exports\Exporter;

/**
 * S-4.1: columns come from the 'newsletter_subscribers' module's own 'list'
 * layout -- see BuildsExporterFromMetadata.
 */
class NewsletterSubscriberExporter extends Exporter
{
    use BuildsExporterFromMetadata;

    public static function moduleKey(): string
    {
        return 'newsletter_subscribers';
    }
}
