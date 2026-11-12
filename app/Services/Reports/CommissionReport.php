<?php

namespace App\Services\Reports;

use App\Enums\CommissionStatus;
use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Rep performance and commission figures for one company. */
class CommissionReport
{
    public function __construct(public readonly int $companyId) {}

    public function orders(): Builder
    {
        return SalesOrder::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->whereNull('deleted_at')
            ->where('status', '!=', SalesOrderStatus::Cancelled);
    }

    /** Performance per rep for a period (orders by order date) plus commission owed to date. */
    public function repCards(CarbonImmutable $from, CarbonImmutable $to, ?int $onlyRepId = null): Collection
    {
        $reps = User::withoutGlobalScopes()
            ->whereHas('companies', fn ($q) => $q->whereKey($this->companyId))
            ->when($onlyRepId, fn ($q) => $q->whereKey($onlyRepId))
            ->where('is_active', true)
            ->where('is_sales_rep', true)
            ->orderBy('name')
            ->get(['id', 'name', 'commission_percent', 'monthly_target']);

        $period = $this->orders()
            ->invoicedBetween($from->toDateString(), $to->toDateString())
            ->selectRaw('sales_rep_id, COUNT(*) as orders, SUM(subtotal) as sales, SUM(gp_amount) as gp, SUM(commission_total) as commission')
            ->groupBy('sales_rep_id')->get()->keyBy('sales_rep_id');

        $unpaid = $this->orders()->commission(CommissionStatus::Unpaid)
            ->selectRaw('sales_rep_id, COUNT(*) as orders, SUM(commission_total) as amount')
            ->groupBy('sales_rep_id')->get()->keyBy('sales_rep_id');

        $book = $this->orders()->notYetInvoiced()
            ->selectRaw('sales_rep_id, COUNT(*) as orders, SUM(subtotal) as value')
            ->groupBy('sales_rep_id')->get()->keyBy('sales_rep_id');

        $pending = $this->orders()->commission(CommissionStatus::NotDue)
            ->selectRaw('sales_rep_id, SUM(commission_total) as amount')
            ->groupBy('sales_rep_id')->get()->keyBy('sales_rep_id');

        $paidInPeriod = $this->orders()->whereNotNull('commission_paid_at')
            ->whereDate('commission_paid_at', '>=', $from->toDateString())->whereDate('commission_paid_at', '<=', $to->toDateString())
            ->selectRaw('sales_rep_id, SUM(COALESCE(commission_paid_amount, commission_total)) as amount')
            ->groupBy('sales_rep_id')->get()->keyBy('sales_rep_id');

        $months = max(1, (int) ceil($from->diffInMonths($to)));

        return $reps->map(function (User $rep) use ($period, $unpaid, $pending, $paidInPeriod, $months, $book) {
            $p = $period->get($rep->id);
            $sales = (float) ($p->sales ?? 0);
            $gp = (float) ($p->gp ?? 0);
            $target = $rep->monthly_target ? (float) $rep->monthly_target * $months : null;

            return [
                'id' => $rep->id,
                'name' => $rep->name,
                'initials' => collect(explode(' ', $rep->name))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode(''),
                'orders' => (int) ($p->orders ?? 0),
                'sales' => $sales,
                'gp' => $gp,
                'gp_percent' => $sales > 0 ? $gp / $sales * 100 : 0,
                'commission' => (float) ($p->commission ?? 0),
                'commission_rate' => (float) $rep->commission_percent,
                'target' => $target,
                'target_percent' => $target ? min(999, $sales / $target * 100) : null,
                'unpaid' => (float) ($unpaid->get($rep->id)->amount ?? 0),
                'unpaid_orders' => (int) ($unpaid->get($rep->id)->orders ?? 0),
                'pending' => (float) ($pending->get($rep->id)->amount ?? 0),
                'paid_in_period' => (float) ($paidInPeriod->get($rep->id)->amount ?? 0),
                'order_book' => (float) ($book->get($rep->id)->value ?? 0),
                'order_book_count' => (int) ($book->get($rep->id)->orders ?? 0),
            ];
        })->sortByDesc('sales')->values();
    }

    /** Totals per rep for an arbitrary (already filtered) order query: earned, unpaid, paid, not yet due. */
    public static function totalsByRep(Builder $query): Collection
    {
        $rows = (clone $query)->with('salesRep:id,name')->get(['id', 'sales_rep_id', 'status', 'commission_total', 'commission_paid_at', 'commission_paid_amount']);

        return $rows->groupBy(fn ($o) => $o->salesRep?->name ?? 'Unassigned')->map(fn ($orders, $name) => [
            'rep' => $name,
            'orders' => $orders->count(),
            'earned' => (float) $orders->sum('commission_total'),
            'paid' => (float) $orders->filter(fn ($o) => $o->commission_paid_at)->sum(fn ($o) => (float) ($o->commission_paid_amount ?? $o->commission_total)),
            'unpaid' => (float) $orders->filter(fn ($o) => $o->commissionStatus() === CommissionStatus::Unpaid)->sum('commission_total'),
            'not_due' => (float) $orders->filter(fn ($o) => $o->commissionStatus() === CommissionStatus::NotDue)->sum('commission_total'),
        ])->sortBy('rep')->values();
    }
}
