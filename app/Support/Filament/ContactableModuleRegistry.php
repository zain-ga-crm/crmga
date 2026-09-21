<?php

namespace App\Support\Filament;

use App\Filament\Resources\AffiliateResource;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\NewsletterSubscriberResource;
use App\Filament\Resources\StudentResource;
use App\Models\Affiliate;
use App\Models\Client;
use App\Models\Company;
use App\Models\Lead;
use App\Models\NewsletterSubscriber;
use App\Models\Student;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Model;

/**
 * S-4.5: the DNC and Hot/Warm aggregate views are the first pages that need
 * to go from a module key to "which Eloquent model, which Resource" for more
 * than one Contactable module at once -- every other page either already
 * knows its one model (a DynamicResource) or reads Field/Layout/Change
 * metadata generically. Named list on purpose (SuiteCRM parity, per
 * BACKEND_BRIEF, was 7 Contactable modules -- not every module in the
 * `modules` table is a person/company you'd ever want in a DNC or Hot/Warm
 * view), rather than deriving it from `Module::all()`.
 */
final class ContactableModuleRegistry
{
    /**
     * Every Contactable module that registers do_not_call (all of them,
     * since it's a column the shared contactable() migration macro puts on
     * every one of these tables).
     *
     * @return array<string, array{label: string, model: class-string<Model>, resource: class-string<FilamentResource>}>
     */
    public static function doNotCallModules(): array
    {
        return [
            'leads' => ['label' => 'Leads', 'model' => Lead::class, 'resource' => LeadResource::class],
            'companies' => ['label' => 'Companies', 'model' => Company::class, 'resource' => CompanyResource::class],
            'students' => ['label' => 'Students', 'model' => Student::class, 'resource' => StudentResource::class],
            'clients' => ['label' => 'Clients', 'model' => Client::class, 'resource' => ClientResource::class],
            'affiliates' => ['label' => 'Affiliates', 'model' => Affiliate::class, 'resource' => AffiliateResource::class],
            'newsletter_subscribers' => ['label' => 'Newsletter subscribers', 'model' => NewsletterSubscriber::class, 'resource' => NewsletterSubscriberResource::class],
        ];
    }

    /**
     * Only the Contactable modules that register hot_lead/warm_lead.
     *
     * @return array<string, array{label: string, model: class-string<Model>, resource: class-string<FilamentResource>}>
     */
    public static function hotWarmModules(): array
    {
        return [
            'leads' => ['label' => 'Leads', 'model' => Lead::class, 'resource' => LeadResource::class],
            'companies' => ['label' => 'Companies', 'model' => Company::class, 'resource' => CompanyResource::class],
            'students' => ['label' => 'Students', 'model' => Student::class, 'resource' => StudentResource::class],
        ];
    }

    /**
     * Every model listed above uses the Contactable trait, so fullName()
     * always exists in practice -- but that trait isn't on the base Eloquent
     * Model type a generic table column's $record is typed as, hence the
     * same method_exists() guard FieldTypeRegistry's own do_not_call handling
     * already uses for the same reason.
     */
    public static function fullNameOf(Model $record): string
    {
        if (method_exists($record, 'fullName')) {
            $name = $record->fullName();

            return is_string($name) ? $name : '';
        }

        return '';
    }
}
