<?php

namespace App\Filament\Resources\SalesOrders\RelationManagers;

use App\Enums\LineStage;
use App\Filament\Support\PricedLineForm;
use App\Filament\Support\PricedLinesTable;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Order lines';

    #[On('refresh-lines')]
    public function refreshLines(): void {}

    /** Lines stay editable until a purchase order exists for the order. */
    public function canEditLines(): bool
    {
        return $this->getOwnerRecord()->status->isOpen() && $this->getOwnerRecord()->purchaseOrders()->doesntExist();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(PricedLineForm::components($this->getOwnerRecord()));
    }

    public function table(Table $table): Table
    {
        return PricedLinesTable::configure($table, $this, editable: $this->canEditLines())
            ->toolbarActions([
                BulkAction::make('stage')
                    ->label('Set production stage')
                    ->schema([Select::make('stage')->options(LineStage::class)->required()])
                    ->action(fn (Collection $records, array $data) => $records->each->update(['stage' => $data['stage']]))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
