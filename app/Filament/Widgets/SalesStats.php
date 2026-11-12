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

    public static function canView(): bool
    {
        return \App\Support\Access::isAdmin();
    }

    protected function getStats(): array
    {
        $companyId = Filament::getTenant()?->id;
        $money = fn ($v) => 'R '.number_format((float) $v, 0, '.', ' ');
        $monthStart = now()->startOfMonth();

        $orders = SalesOrder::where('company_id', $companyId)->where('status', '!=', SalesOrderStatus::Cancelled);
        $invoicedThisMonth = (clone $orders)->invoicedBetween($monthStart->toDateString(), now()->toDateString());
        $salesThisMonth = (clone $invoicedThisMonth)->sum('subtotal');
        $gpThisMonth = (clone $invoicedThisMonth)->sum('gp_amount');
        $book = (clone $orders)->notYetInvoiced();

        $quotes = Quote::where('company_id', $companyId);
        $openQuotes = (clone $quotes)->whereIn('status', [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Accepted]);
        $decided90 = (clone $quotes)->where('quote_date', '>=', now()->subDays(90))
            ->whereIn('status', [QuoteStatus::Converted, QuoteStatus::Declined, QuoteStatus::Expired])->count();
        $won90 = (clone $quotes)->where('quote_date', '>=', now()->subDays(90))->where('status', QuoteStatus::Converted)->count();

        $daily = (clone $orders)->invoicedBetween(now()->subDays(30)->toDateString(), now()->toDateString())
            ->selectRaw('date(coalesce(invoice_date, order_date)) as d, SUM(subtotal) as total')->groupBy('d')->orderBy('d')->pluck('total')->map(fn ($v) => (float) $v)->all();

        return [
            Stat::make('Invoiced this month (ex VAT)', $money($salesThisMonth))
                ->description('GP '.$money($gpThisMonth).($salesThisMonth > 0 ? ' · '.number_format($gpThisMonth / $salesThisMonth * 100, 1).'%' : ''))
                ->chart($daily ?: null)->color('success'),
            Stat::make('Order book (not yet invoiced)', $money((clone $book)->sum('subtotal')))
                ->description(number_format((clone $book)->count()).' open order(s)')->color('info'),
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
