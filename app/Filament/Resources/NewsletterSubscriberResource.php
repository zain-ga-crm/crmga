<?php

namespace App\Filament\Resources;

use App\Filament\Exports\NewsletterSubscriberExporter;
use App\Filament\Resources\Concerns\BuildsResourceFromMetadata;
use App\Filament\Resources\NewsletterSubscriberResource\Pages;
use App\Models\NewsletterSubscriber;
use Filament\Actions\Exports\Exporter;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * S-4.1: another DynamicResource -- everything here comes from the
 * 'newsletter_subscribers' module's own tenant_fields/tenant_layouts, same
 * as Lead/Company.
 */
class NewsletterSubscriberResource extends Resource
{
    use BuildsResourceFromMetadata;

    protected static ?string $model = NewsletterSubscriber::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Directory';

    public static function moduleKey(): string
    {
        return 'newsletter_subscribers';
    }

    /**
     * @return class-string<Exporter>
     */
    public static function exporter(): ?string
    {
        return NewsletterSubscriberExporter::class;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNewsletterSubscribers::route('/'),
            'create' => Pages\CreateNewsletterSubscriber::route('/create'),
            'view' => Pages\ViewNewsletterSubscriber::route('/{record}'),
            'edit' => Pages\EditNewsletterSubscriber::route('/{record}/edit'),
        ];
    }
}
