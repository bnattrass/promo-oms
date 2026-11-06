<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\ProductVariant;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('full_code')
            ->columns([
                TextColumn::make('full_code')->label('Code')->searchable()->copyable(),
                TextColumn::make('colour_name')->label('Colour')->placeholder('-'),
                TextColumn::make('size')->placeholder('-'),
                TextColumn::make('cost_price')->label('Cost (ex VAT)')->money('ZAR')->sortable(),
                TextColumn::make('stock_on_hand')->label('In stock')->numeric()->sortable()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('incoming')->label('Incoming')
                    ->getStateUsing(fn (ProductVariant $record) => collect($record->stock_incoming)
                        ->map(fn ($i) => number_format($i['qty']).($i['eta'] ? ' ('.substr($i['eta'], 0, 10).')' : ''))
                        ->implode(', '))
                    ->placeholder('-'),
                TextColumn::make('stock_synced_at')->label('Stock as of')->since(),
            ]);
    }
}
