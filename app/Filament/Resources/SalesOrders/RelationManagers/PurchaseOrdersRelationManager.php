<?php

namespace App\Filament\Resources\SalesOrders\RelationManagers;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class PurchaseOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'purchaseOrders';

    protected static ?string $title = 'Purchase orders';

    #[On('refresh-lines')]
    public function refreshList(): void {}

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('number')
            ->columns([
                TextColumn::make('number')->weight('bold'),
                TextColumn::make('supplier.name'),
                TextColumn::make('status')->badge(),
                TextColumn::make('supplier_reference')->label('Supplier ref')->placeholder('-'),
                TextColumn::make('expected_date')->date('j M Y')->placeholder('-'),
                TextColumn::make('total')->money('ZAR'),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->url(fn ($record) => PurchaseOrderResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
