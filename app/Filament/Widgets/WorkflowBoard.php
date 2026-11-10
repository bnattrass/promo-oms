<?php

namespace App\Filament\Widgets;

use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\Quote;
use App\Models\SalesOrder;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Home-page overview of the whole job flow: quotes -> sales orders -> production -> invoicing,
 * with counts, values and the oldest jobs waiting at each stage.
 */
class WorkflowBoard extends Widget
{
    protected string $view = 'filament.widgets.workflow-board';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $companyId = Filament::getTenant()?->id;

        $quoteStage = fn (string $label, array $statuses, string $tab) => $this->stage(
            $label,
            Quote::query()->with('customer:id,name')->where('company_id', $companyId)->whereIn('status', $statuses),
            'quote_date',
            fn (Quote $q) => QuoteResource::getUrl('edit', ['record' => $q]),
            QuoteResource::getUrl('index', ['activeTab' => $tab]),
        );
        $orderStage = fn (string $label, array $statuses, string $tab, ?callable $scope = null) => $this->stage(
            $label,
            SalesOrder::query()->with('customer:id,name')->where('company_id', $companyId)->whereIn('status', $statuses)
                ->when($scope, fn (Builder $q) => $scope($q)),
            'order_date',
            fn (SalesOrder $o) => SalesOrderResource::getUrl('edit', ['record' => $o]),
            SalesOrderResource::getUrl('index', ['activeTab' => $tab]),
        );

        return [
            'columns' => [
                [
                    'title' => 'Quotes',
                    'colour' => '#6366f1',
                    'stages' => [
                        $quoteStage('Draft', [QuoteStatus::Draft], 'draft'),
                        $quoteStage('Sent - waiting on customer', [QuoteStatus::Sent], 'sent'),
                        $quoteStage('Accepted - convert to order', [QuoteStatus::Accepted], 'accepted'),
                    ],
                ],
                [
                    'title' => 'Sales orders',
                    'colour' => '#0ea5e9',
                    'stages' => [
                        $orderStage('Awaiting deposit', [SalesOrderStatus::AwaitingDeposit], 'deposit'),
                        $orderStage('Artwork / to order', [SalesOrderStatus::Confirmed, SalesOrderStatus::Artwork, SalesOrderStatus::ArtworkApproved], 'to_order'),
                    ],
                ],
                [
                    'title' => 'Production',
                    'colour' => '#f59e0b',
                    'stages' => [
                        $orderStage('Ordered from supplier', [SalesOrderStatus::Ordered], 'ordered'),
                        $orderStage('In production', [SalesOrderStatus::InProduction], 'production'),
                        $orderStage('Ready for dispatch', [SalesOrderStatus::Ready], 'ready'),
                    ],
                ],
                [
                    'title' => 'Dispatch & invoicing',
                    'colour' => '#10b981',
                    'stages' => [
                        $orderStage('Dispatched - to invoice', [SalesOrderStatus::PartiallyDispatched, SalesOrderStatus::Dispatched], 'to_invoice'),
                        $orderStage('Invoiced (last 30 days)', [SalesOrderStatus::Invoiced], 'invoiced',
                            fn (Builder $q) => $q->where('updated_at', '>=', now()->subDays(30))),
                    ],
                ],
            ],
            'money' => fn ($v) => 'R '.number_format((float) $v, 0, '.', ' '),
        ];
    }

    private function stage(string $label, Builder $query, string $dateColumn, callable $itemUrl, string $listUrl): array
    {
        $count = (clone $query)->count();
        $value = (float) (clone $query)->sum('subtotal');
        $items = (clone $query)->orderBy($dateColumn)->orderBy('id')->limit(5)->get()
            ->map(fn ($doc) => [
                'number' => $doc->number,
                'customer' => $doc->customer?->name ?? '-',
                'value' => (float) $doc->subtotal,
                'age' => (int) $doc->{$dateColumn}?->startOfDay()->diffInDays(today()),
                'overdue' => $doc instanceof SalesOrder && $doc->required_by && $doc->required_by->isPast(),
                'url' => $itemUrl($doc),
            ]);

        return compact('label', 'count', 'value', 'items', 'listUrl');
    }
}
