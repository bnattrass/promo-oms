<?php

namespace App\Filament\Widgets;

use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Quote;
use App\Models\SalesOrder;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $companyId = Filament::getTenant()?->id;
        $money = fn ($v) => 'R '.number_format((float) $v, 0, '.', ' ');
        $monthStart = now()->startOfMonth();

        $orders = SalesOrder::where('company_id', $companyId)->where('status', '!=', SalesOrderStatus::Cancelled);
        $salesThisMonth = (clone $orders)->where('order_date', '>=', $monthStart)->sum('subtotal');
        $gpThisMonth = (clone $orders)->where('order_date', '>=', $monthStart)->sum('gp_amount');

        $quotes = Quote::where('company_id', $companyId);
        $openQuotes = (clone $quotes)->whereIn('status', [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Accepted]);
        $decided90 = (clone $quotes)->where('quote_date', '>=', now()->subDays(90))
            ->whereIn('status', [QuoteStatus::Converted, QuoteStatus::Declined, QuoteStatus::Expired])->count();
        $won90 = (clone $quotes)->where('quote_date', '>=', now()->subDays(90))->where('status', QuoteStatus::Converted)->count();

        $daily = (clone $orders)->where('order_date', '>=', now()->subDays(30))
            ->selectRaw('order_date, SUM(subtotal) as total')->groupBy('order_date')->orderBy('order_date')->pluck('total')->map(fn ($v) => (float) $v)->all();

        return [
            Stat::make('Sales this month (ex VAT)', $money($salesThisMonth))
                ->description('GP '.$money($gpThisMonth).($salesThisMonth > 0 ? ' · '.number_format($gpThisMonth / $salesThisMonth * 100, 1).'%' : ''))
                ->chart($daily ?: null)->color('success'),
            Stat::make('Open quotes', number_format((clone $openQuotes)->count()))
                ->description($money((clone $openQuotes)->sum('subtotal')).' in the pipeline'),
            Stat::make('Quote win rate (90 days)', $decided90 ? number_format($won90 / $decided90 * 100, 0).'%' : '-')
                ->description("{$won90} won of {$decided90} decided"),
            Stat::make('Awaiting deposit', number_format((clone $orders)->where('status', SalesOrderStatus::AwaitingDeposit)->count()))
                ->description($money((clone $orders)->where('status', SalesOrderStatus::AwaitingDeposit)->sum('deposit_required')).' outstanding')
                ->color('warning'),
        ];
    }
}
