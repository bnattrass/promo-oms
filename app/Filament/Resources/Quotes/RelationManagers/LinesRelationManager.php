<?php

namespace App\Filament\Resources\Quotes\RelationManagers;

use App\Filament\Support\PricedLineForm;
use App\Filament\Support\PricedLinesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Livewire\Attributes\On;

class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Quote lines';

    #[On('refresh-lines')]
    public function refreshLines(): void
    {
        // Re-render after header actions change lines.
    }

    public function isReadOnly(): bool
    {
        return ! $this->getOwnerRecord()->status->isEditable();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(PricedLineForm::components($this->getOwnerRecord()));
    }

    public function table(Table $table): Table
    {
        return PricedLinesTable::configure($table, $this, editable: ! $this->isReadOnly());
    }
}
