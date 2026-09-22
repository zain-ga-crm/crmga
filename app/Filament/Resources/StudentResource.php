<?php

namespace App\Filament\Resources;

use App\Filament\Exports\StudentExporter;
use App\Filament\Resources\Concerns\BuildsResourceFromMetadata;
use App\Filament\Resources\Concerns\HasActivityRelationManagers;
use App\Filament\Resources\StudentResource\Pages;
use App\Models\Student;
use Filament\Actions\Exports\Exporter;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;

/**
 * S-4.1: another DynamicResource -- everything here comes from the 'students'
 * module's own tenant_fields/tenant_layouts, same as Lead/Company.
 */
class StudentResource extends Resource
{
    use BuildsResourceFromMetadata;
    use HasActivityRelationManagers;

    protected static ?string $model = Student::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Directory';

    public static function moduleKey(): string
    {
        return 'students';
    }

    /**
     * @return class-string<Exporter>
     */
    public static function exporter(): ?string
    {
        return StudentExporter::class;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStudents::route('/'),
            'create' => Pages\CreateStudent::route('/create'),
            'view' => Pages\ViewStudent::route('/{record}'),
            'edit' => Pages\EditStudent::route('/{record}/edit'),
        ];
    }
}
