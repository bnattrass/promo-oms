<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Livewire\Attributes\Url;

class ListSalesOrders extends ListRecords
{
    protected static string $resource = SalesOrderResource::class;

    #[Url]
    public ?string $activeTab = null;

    public function getTabs(): array
    {
        $closed = [SalesOrderStatus::Invoiced, SalesOrderStatus::Closed, SalesOrderStatus::Cancelled];

        $in = fn (array $statuses) => fn ($query) => $query->whereIn('status', $statuses);

        return [
            'open' => Tab::make('Open')->modifyQueryUsing(fn ($query) => $query->whereNotIn('status', $closed)),
            'deposit' => Tab::make('Awaiting deposit')->modifyQueryUsing($in([SalesOrderStatus::AwaitingDeposit])),
            'to_order' => Tab::make('Artwork / to order')->modifyQueryUsing($in([SalesOrderStatus::Confirmed, SalesOrderStatus::Artwork, SalesOrderStatus::ArtworkApproved])),
            'ordered' => Tab::make('Ordered')->modifyQueryUsing($in([SalesOrderStatus::Ordered])),
            'production' => Tab::make('In production')->modifyQueryUsing($in([SalesOrderStatus::InProduction])),
            'ready' => Tab::make('Ready')->modifyQueryUsing($in([SalesOrderStatus::Ready])),
            'to_invoice' => Tab::make('To invoice')->modifyQueryUsing($in([SalesOrderStatus::PartiallyDispatched, SalesOrderStatus::Dispatched])),
            'invoiced' => Tab::make('Invoiced')->modifyQueryUsing($in([SalesOrderStatus::Invoiced])),
            'all' => Tab::make('All'),
        ];
    }
}
