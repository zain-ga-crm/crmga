<?php

namespace App\Filament\RelationManagers;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Support\Email\EmailSender;
use App\Support\Filament\ContactableModuleRegistry;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * S-5.4: the "compose email" half of the communication UI -- the backend
 * (EmailSender, MergeFieldResolver, SendRecordEmailJob, RecordEmail) was
 * already built and tested (Z-5.8) with exactly this screen in mind, per
 * EmailSender's own docblock ("S-5.4's compose screen calls into this
 * directly"). Was list-only until now (see the superseded docblock this
 * replaces) because there was nowhere else real outbound email could come
 * from without faking a row; now there is.
 */
class EmailsRelationManager extends RelationManager
{
    protected static string $relationship = 'emails';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject_line')
            ->columns([
                TextColumn::make('subject_line')->label('Subject')->placeholder('(no subject)'),
                TextColumn::make('from_address'),
                TextColumn::make('status')->badge(),
                TextColumn::make('sent_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->defaultSort('sent_at', 'desc')
            ->headerActions([
                $this->composeAction(),
            ]);
    }

    private function composeAction(): Action
    {
        $record = $this->getOwnerRecord();
        $moduleKey = ContactableModuleRegistry::moduleKeyFor($record);

        return Action::make('compose')
            ->label('Compose email')
            ->icon('heroicon-o-paper-airplane')
            ->visible($moduleKey !== null)
            ->form([
                Select::make('template_id')
                    ->label('Template')
                    ->options(fn (): array => EmailTemplate::query()
                        ->where(fn ($query) => $query->whereNull('module_key')->orWhere('module_key', $moduleKey))
                        ->pluck('name', 'id')
                        ->all())
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $template = $state === null ? null : EmailTemplate::find($state);
                        if ($template !== null) {
                            $set('subject', $template->subject);
                            $set('body_html', $template->body_html);
                        }
                    })
                    ->helperText('Optional -- prefills subject and body, both still editable before sending.')
                    ->columnSpanFull(),
                TagsInput::make('to')
                    ->label('To')
                    ->default(fn (): array => array_filter([$record->getAttribute('primary_email')]))
                    ->required()
                    ->columnSpanFull(),
                TagsInput::make('cc')
                    ->label('Cc')
                    ->columnSpanFull(),
                TextInput::make('subject')
                    ->required()
                    ->columnSpanFull(),
                RichEditor::make('body_html')
                    ->label('Body')
                    ->required()
                    ->helperText('Use {{field_name}} to merge a value from this record, e.g. {{full_name}}.')
                    ->columnSpanFull(),
            ])
            ->action(function (array $data) use ($record, $moduleKey): void {
                /** @var User|null $actor */
                $actor = Auth::user();

                app(EmailSender::class)->send(
                    record: $record,
                    moduleKey: (string) $moduleKey,
                    to: self::stringList($data['to'] ?? null),
                    subject: is_string($data['subject'] ?? null) ? $data['subject'] : '',
                    bodyHtml: is_string($data['body_html'] ?? null) ? $data['body_html'] : '',
                    cc: self::stringList($data['cc'] ?? null),
                    actor: $actor,
                );

                Notification::make()->title('Email queued for sending')->success()->send();
            });
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
