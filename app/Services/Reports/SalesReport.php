<?php

namespace App\Services\Reports;

use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Sales figures for one company and date range. Sales = invoiced sales orders by invoice date, ex VAT. Quotes are counted by quote date. Grouping is done in PHP so it works on SQLite and MySQL.
 */
class SalesReport
{
    public readonly CarbonImmutable $from;

    public readonly CarbonImmutable $to;

    private ?Collection $orders = null;

    private ?Collection $quotes = null;

    public function __construct(public readonly int $companyId, string $from, string $to, public readonly ?int $repId = null)
    {
        $this->from = CarbonImmutable::parse($from)->startOfDay();
        $this->to = CarbonImmutable::parse($to)->endOfDay();
    }

    /** Invoiced orders in the range, by invoice date. Not-yet-invoiced orders are the order book, not sales. */
    public function orders(): Collection
    {
        return $this->orders ??= SalesOrder::withoutGlobalScopes()
            ->with('customer:id,name', 'salesRep:id,name')
            ->where('company_id', $this->companyId)
            ->whereNull('deleted_at')
            ->invoicedBetween($this->from->toDateString(), $this->to->toDateString())
            ->when($this->repId, fn ($q) => $q->where('sales_rep_id', $this->repId))
            ->get(['id', 'number', 'customer_id', 'sales_rep_id', 'order_date', 'invoice_date', 'invoice_number', 'status', 'subtotal', 'cost_total', 'gp_amount', 'commission_total', 'total', 'quote_id']);
    }

    /** Value of confirmed orders not yet invoiced (current, not limited to the date range). */
    public function orderBook(): array
    {
        $q = SalesOrder::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->whereNull('deleted_at')
            ->notYetInvoiced()
            ->when($this->repId, fn ($q) => $q->where('sales_rep_id', $this->repId));

        return ['count' => (clone $q)->count(), 'value' => (float) (clone $q)->sum('subtotal'), 'gp' => (float) (clone $q)->sum('gp_amount')];
    }

    public function quotes(): Collection
    {
        return $this->quotes ??= Quote::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->whereNull('deleted_at')
            ->whereDate('quote_date', '>=', $this->from->toDateString())
            ->whereDate('quote_date', '<=', $this->to->toDateString())
            ->when($this->repId, fn ($q) => $q->where('sales_rep_id', $this->repId))
            ->get(['id', 'sales_rep_id', 'status', 'subtotal', 'gp_amount', 'quote_date']);
    }

    public function summary(): array
    {
        $o = $this->orders();
        $q = $this->quotes();
        $sales = (float) $o->sum('subtotal');
        $gp = (float) $o->sum('gp_amount');
        $decided = $q->whereIn('status', [QuoteStatus::Converted, QuoteStatus::Declined, QuoteStatus::Expired]);
        $won = $q->where('status', QuoteStatus::Converted);

        return [
            'sales' => $sales,
            'gp' => $gp,
            'gp_percent' => $sales > 0 ? $gp / $sales * 100 : 0,
            'commission' => (float) $o->sum('commission_total'),
            'orders' => $o->count(),
            'average_order' => $o->count() ? $sales / $o->count() : 0,
            'quotes' => $q->count(),
            'quote_value' => (float) $q->sum('subtotal'),
            'win_rate' => $decided->count() ? $won->count() / $decided->count() * 100 : null,
            'order_book' => $this->orderBook(),
            'open_pipeline' => (float) $q->whereIn('status', [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Accepted])->sum('subtotal'),
            'target' => $this->target(),
        ];
    }

    /** Monthly targets of the reps in scope, times the number of months in the range. */
    public function target(): ?float
    {
        $months = $this->months()->count();
        $users = User::withoutGlobalScopes()
            ->whereHas('companies', fn ($q) => $q->whereKey($this->companyId))
            ->when($this->repId, fn ($q) => $q->whereKey($this->repId))
            ->whereNotNull('monthly_target')
            ->sum('monthly_target');

        return $users > 0 ? (float) $users * $months : null;
    }

    public function byRep(): Collection
    {
        $quotes = $this->quotes()->groupBy(fn ($q) => $q->sales_rep_id ?? 0);
        $reps = User::withoutGlobalScopes()->whereIn('id', $this->orders()->pluck('sales_rep_id')->merge($quotes->keys())->filter()->unique())
            ->get(['id', 'name', 'monthly_target'])->keyBy('id');
        $months = $this->months()->count();

        return $this->orders()->groupBy(fn ($o) => $o->sales_rep_id ?? 0)
            ->union($quotes->map(fn () => collect()))
            ->map(function ($orders, $repId) use ($quotes, $reps, $months) {
                $sales = (float) $orders->sum('subtotal');
                $gp = (float) $orders->sum('gp_amount');
                $repQuotes = $quotes->get($repId, collect());
                $decided = $repQuotes->whereIn('status', [QuoteStatus::Converted, QuoteStatus::Declined, QuoteStatus::Expired])->count();
                $target = $reps->get($repId)?->monthly_target ? (float) $reps->get($repId)->monthly_target * $months : null;

                return [
                    'rep' => $reps->get($repId)?->name ?? 'Unassigned',
                    'orders' => $orders->count(),
                    'sales' => $sales,
                    'gp' => $gp,
                    'gp_percent' => $sales > 0 ? $gp / $sales * 100 : 0,
                    'commission' => (float) $orders->sum('commission_total'),
                    'quotes' => $repQuotes->count(),
                    'win_rate' => $decided ? $repQuotes->where('status', QuoteStatus::Converted)->count() / $decided * 100 : null,
                    'target' => $target,
                    'target_percent' => $target ? $sales / $target * 100 : null,
                ];
            })
            ->sortByDesc('sales')->values();
    }

    public function byMonth(): Collection
    {
        $orders = $this->orders()->groupBy(fn ($o) => $o->salesDate()->format('Y-m'));
        $quotes = $this->quotes()->groupBy(fn ($q) => $q->quote_date->format('Y-m'));

        return $this->months()->map(function (CarbonImmutable $month) use ($orders, $quotes) {
            $key = $month->format('Y-m');
            $o = $orders->get($key, collect());
            $sales = (float) $o->sum('subtotal');
            $gp = (float) $o->sum('gp_amount');

            return [
                'month' => $month->format('M Y'),
                'orders' => $o->count(),
                'sales' => $sales,
                'gp' => $gp,
                'gp_percent' => $sales > 0 ? $gp / $sales * 100 : 0,
                'quotes' => $quotes->get($key, collect())->count(),
            ];
        });
    }

    public function topCustomers(int $limit = 15): Collection
    {
        return $this->orders()->groupBy('customer_id')->map(function ($orders) {
            $sales = (float) $orders->sum('subtotal');
            $gp = (float) $orders->sum('gp_amount');

            return [
                'customer' => $orders->first()->customer?->name ?? '-',
                'orders' => $orders->count(),
                'sales' => $sales,
                'gp' => $gp,
                'gp_percent' => $sales > 0 ? $gp / $sales * 100 : 0,
            ];
        })->sortByDesc('sales')->take($limit)->values();
    }

    /** Sales split by line type: catalogue items vs courier, design, setup, services. */
    public function byLineType(): Collection
    {
        $lines = SalesOrderLine::query()
            ->whereIn('sales_order_id', $this->orders()->pluck('id'))
            ->get(['line_type', 'line_total', 'gp_amount']);

        return $lines->groupBy(fn ($l) => $l->line_type ?: 'catalogue')->map(function ($group, $type) {
            $sales = (float) $group->sum('line_total');
            $gp = (float) $group->sum('gp_amount');

            return [
                'type' => \App\Enums\LineType::tryFrom($type)?->getLabel() ?? ucfirst($type),
                'lines' => $group->count(),
                'sales' => $sales,
                'gp' => $gp,
                'gp_percent' => $sales > 0 ? $gp / $sales * 100 : 0,
            ];
        })->sortByDesc('sales')->values();
    }

    /** @return Collection<int, CarbonImmutable> first day of each month in range */
    public function months(): Collection
    {
        return collect(CarbonPeriod::create($this->from->startOfMonth(), '1 month', $this->to->startOfMonth()))
            ->map(fn ($d) => CarbonImmutable::instance($d));
    }

    /** Flat rows for CSV export: one row per sales order. */
    public function csvRows(): array
    {
        $rows = [['Order', 'Order date', 'Invoice', 'Invoice date', 'Customer', 'Sales rep', 'Status', 'Sales ex VAT', 'Cost', 'GP', 'GP %', 'Commission', 'Total incl VAT']];
        foreach ($this->orders()->sortBy(fn ($o) => $o->salesDate()) as $o) {
            $rows[] = [
                $o->number, $o->order_date->format('Y-m-d'), $o->invoice_number, $o->invoice_date?->format('Y-m-d'), $o->customer?->name, $o->salesRep?->name ?? 'Unassigned',
                $o->status->getLabel(), round((float) $o->subtotal, 2), round((float) $o->cost_total, 2), round((float) $o->gp_amount, 2),
                (float) $o->subtotal > 0 ? round((float) $o->gp_amount / (float) $o->subtotal * 100, 1) : 0,
                round((float) $o->commission_total, 2), round((float) $o->total, 2),
            ];
        }

        return $rows;
    }
}
