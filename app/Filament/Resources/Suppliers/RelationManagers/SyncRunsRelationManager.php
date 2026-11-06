<?php

namespace App\Filament\Resources\Suppliers\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SyncRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'syncRuns';

    protected static ?string $title = 'Sync runs';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('started_at')->dateTime('j M H:i')->sortable(),
                TextColumn::make('module')->formatStateUsing(fn ($state) => str($state)->replace('_', ' ')->title()),
                TextColumn::make('mode')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'success' => 'success', 'failed' => 'danger', 'skipped' => 'warning', default => 'info',
                }),
                TextColumn::make('records_received')->label('Received')->numeric(),
                TextColumn::make('records_created')->label('New')->numeric(),
                TextColumn::make('records_updated')->label('Updated')->numeric(),
                TextColumn::make('records_deleted')->label('Removed')->numeric(),
                TextColumn::make('message')->limit(60)->tooltip(fn ($record) => $record->message)->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['success' => 'Success', 'failed' => 'Failed', 'skipped' => 'Skipped', 'running' => 'Running']),
            ]);
    }
}
