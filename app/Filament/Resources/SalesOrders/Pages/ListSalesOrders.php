<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListSalesOrders extends ListRecords
{
    protected static string $resource = SalesOrderResource::class;

    public function getTabs(): array
    {
        $closed = [SalesOrderStatus::Invoiced, SalesOrderStatus::Closed, SalesOrderStatus::Cancelled];

        return [
            'open' => Tab::make('Open')->modifyQueryUsing(fn ($query) => $query->whereNotIn('status', $closed)),
            'deposit' => Tab::make('Awaiting deposit')->modifyQueryUsing(fn ($query) => $query->where('status', SalesOrderStatus::AwaitingDeposit)),
            'production' => Tab::make('In production')->modifyQueryUsing(fn ($query) => $query->whereIn('status', [SalesOrderStatus::Ordered, SalesOrderStatus::InProduction])),
            'all' => Tab::make('All'),
        ];
    }
}
