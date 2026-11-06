<?php

namespace App\Filament\Widgets;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\SalesOrder;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class OpenOrdersTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Open orders by due date';

    public function table(Table $table): Table
    {
        return $table
            ->query(SalesOrder::query()
                ->with('customer')
                ->where('company_id', Filament::getTenant()?->id)
                ->whereNotIn('status', [SalesOrderStatus::Invoiced, SalesOrderStatus::Closed, SalesOrderStatus::Cancelled])
                ->orderByRaw('required_by is null, required_by'))
            ->paginated([10, 25])
            ->recordUrl(fn (SalesOrder $record) => SalesOrderResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->weight('bold'),
                TextColumn::make('customer.name')->wrap(),
                TextColumn::make('reference')->limit(30),
                TextColumn::make('status')->badge(),
                TextColumn::make('required_by')->date('j M Y')->placeholder('-')
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : null),
                TextColumn::make('total')->money('ZAR'),
            ]);
    }
}
