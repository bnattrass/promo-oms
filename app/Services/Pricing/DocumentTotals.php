<?php

namespace App\Services\Pricing;

use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesOrder;

/** Re-prices every line of a quote or sales order and rolls the totals up to the header. */
class DocumentTotals
{
    public function __construct(private readonly LinePricer $pricer) {}

    public function recalculate(Quote|SalesOrder $document, bool $reprice = true): void
    {
        $document->loadMissing('lines', 'company');
        $setupSeparately = $document instanceof Quote ? (bool) $document->show_setup_separately : true;
        $rate = $document->company->vatRate();

        $cost = $subtotal = $vat = $gp = $commission = 0.0;

        foreach ($document->lines as $line) {
            if ($reprice) {
                $line->fill($this->pricer->price($line->toArray(), $line->supplier_id, $setupSeparately))->save();
            }

            $cost += (float) $line->total_cost;
            $subtotal += (float) $line->line_total;
            $gp += (float) $line->gp_amount;
            $commission += (float) $line->commission_amount;
            $vat += Vat::lineVat((float) $line->unit_price, (int) $line->quantity, $rate)
                + Vat::lineVat((float) $line->setup_price, 1, $rate);
        }

        $document->forceFill([
            'cost_total' => round($cost, 2),
            'subtotal' => round($subtotal, 2),
            'vat_total' => round($vat, 2),
            'total' => round($subtotal + $vat, 2),
            'gp_amount' => round($gp, 2),
            'gp_percent' => $subtotal > 0 ? round($gp / $subtotal * 100, 2) : 0,
            'commission_total' => round($commission, 2),
        ])->save();
    }

    /** Apply one GP% to every line of a quote ("whole quote" margin). */
    public function applyGpToAllLines(Quote $quote, float $gpPercent): void
    {
        $quote->lines()->update(['gp_percent' => $gpPercent]);
        $quote->unsetRelation('lines');
        $this->recalculate($quote);
    }

    public function recalculatePurchaseOrder(PurchaseOrder $po): void
    {
        $po->loadMissing('lines', 'company');
        $rate = $po->company->vatRate();
        $subtotal = $vat = 0.0;

        foreach ($po->lines as $line) {
            $total = round((float) $line->unit_cost * $line->quantity + (float) $line->setup_cost, 2);
            $line->update(['line_total' => $total]);
            $subtotal += $total;
            $vat += Vat::lineVat((float) $line->unit_cost, (int) $line->quantity, $rate) + Vat::lineVat((float) $line->setup_cost, 1, $rate);
        }

        $po->update(['subtotal' => round($subtotal, 2), 'vat_total' => round($vat, 2), 'total' => round($subtotal + $vat, 2)]);
    }
}
