<?php

namespace App\Filament\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * S-4.3 (relation-manager half): read-only, by design. Every Email row
 * today is written by PollImapMailboxesJob via InboundEmailProcessor
 * (threaded/deduped by message_id/in_reply_to) -- there is no outbound
 * composer anywhere in the app, so a manual create here would produce a
 * row that looks like a real sent/received email but isn't one, with no
 * threading behind it. If outbound composing is ever built, this gets a
 * create form then; until then, list-only avoids that footgun.
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
            ->defaultSort('sent_at', 'desc');
    }
}
