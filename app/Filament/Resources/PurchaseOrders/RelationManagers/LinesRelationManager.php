<?php

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Enums\PurchaseOrderStatus;
use App\Services\Pricing\DocumentTotals;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function isReadOnly(): bool
    {
        return $this->getOwnerRecord()->status !== PurchaseOrderStatus::Draft;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('item_code'),
            Textarea::make('description')->required()->columnSpan(2),
            TextInput::make('quantity')->numeric()->required(),
            TextInput::make('unit_cost')->label('Unit cost incl. branding (ex VAT)')->numeric()->prefix('R'),
            TextInput::make('setup_cost')->numeric()->prefix('R'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('item_code')->label('Code'),
                TextColumn::make('description')->wrap()
                    ->description(fn ($record) => collect($record->brandings)->map(fn ($b) => trim(($b['position_name'] ?? $b['position_code'] ?? '').': '
                        .($b['method_name'] ?? '').' '.($b['print_code'] ?? $b['priced_code'] ?? '')))->implode(' · ') ?: null),
                TextColumn::make('quantity')->numeric(),
                TextColumn::make('quantity_received')->label('Received')->numeric()
                    ->color(fn ($state, $record) => $state >= $record->quantity ? 'success' : ($state > 0 ? 'warning' : null)),
                TextColumn::make('unit_cost')->money('ZAR'),
                TextColumn::make('setup_cost')->money('ZAR'),
                TextColumn::make('line_total')->money('ZAR')->weight('bold'),
            ])
            ->recordActions([
                EditAction::make()->after(fn () => app(DocumentTotals::class)->recalculatePurchaseOrder($this->getOwnerRecord()->refresh())),
            ]);
    }
}
