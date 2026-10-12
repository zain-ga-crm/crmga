<?php

namespace App\Filament\Pages;

use App\Models\Call;
use App\Support\Filament\ContactableModuleRegistry;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * S-4.1's deferred Call log screen. Call is polymorphic -- it morphs to
 * whichever of the six Contactable modules the call was about, not one
 * fixed module of its own -- so this is a plain page over Call::query()
 * directly (same pattern as DoNotCallList/HotWarmList), not a
 * BuildsResourceFromMetadata resource: that trait assumes one Eloquent
 * model per fixed business module, which doesn't fit a record whose
 * "module" varies row to row.
 *
 * Admin-only rather than per-row ACL: a company-wide call log spanning
 * every module's calls at once is a supervisory review screen (same
 * tier as RoleResource/UserResource), not a workspace that needs
 * Owner/Group-level filtering -- Call itself carries no ACL of its own
 * to filter by either.
 */
class CallLogList extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'Call Log';

    protected static ?string $slug = 'call-log';

    protected static string $view = 'filament.pages.call-log-list';

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->isAdmin();
    }

    public function getTitle(): string
    {
        return 'Call Log';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Call::query()->with(['subject', 'assignedUser']))
            ->columns([
                TextColumn::make('direction')->badge(),
                TextColumn::make('date_start')->label('When')->dateTime()->sortable(),
                TextColumn::make('subject')
                    ->label('Regarding')
                    ->getStateUsing(function (Call $record): string {
                        $subject = $record->subject;

                        return $subject !== null ? (ContactableModuleRegistry::fullNameOf($subject) ?: '—') : '—';
                    }),
                TextColumn::make('duration_minutes')->label('Minutes')->placeholder('—'),
                TextColumn::make('outcome')->placeholder('—'),
                TextColumn::make('assignedUser.name')->label('Owner')->placeholder('—'),
            ])
            ->defaultSort('date_start', 'desc')
            ->actions([
                Action::make('viewSubject')
                    ->label('View')
                    ->visible(fn (Call $record): bool => $record->subject !== null && ContactableModuleRegistry::resourceFor($record->subject) !== null)
                    ->url(function (Call $record): ?string {
                        $subject = $record->subject;
                        if ($subject === null) {
                            return null;
                        }

                        $resource = ContactableModuleRegistry::resourceFor($subject);

                        return $resource !== null ? $resource::getUrl('view', ['record' => $subject]) : null;
                    }),
            ])
            ->paginated([10, 25, 50])
            ->emptyStateHeading('No calls logged yet');
    }
}
