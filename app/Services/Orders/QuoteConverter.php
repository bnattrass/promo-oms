<?php

namespace App\Services\Orders;

use App\Enums\QuoteStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Activity;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Pricing\DocumentTotals;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Accepted quote -> sales order, copying lines, branding and prices. */
class QuoteConverter
{
    public function __construct(private readonly DocumentTotals $totals) {}

    /**
     * @param  string|null  $poFilePath  a file already stored on the local disk (e.g. from a Filament upload)
     */
    public function convert(Quote $quote, ?string $customerPoNumber = null, ?string $poFilePath = null, ?string $poFileName = null): SalesOrder
    {
        if ($quote->salesOrder()->exists()) {
            throw new RuntimeException("Quote {$quote->number} has already been converted.");
        }
        if ($quote->lines()->doesntExist()) {
            throw new RuntimeException('A quote needs at least one line before it can become an order.');
        }

        return DB::transaction(function () use ($quote, $customerPoNumber, $poFilePath, $poFileName) {
            $quote->loadMissing('lines', 'customer');
            $customer = $quote->customer;

            $order = SalesOrder::create([
                'company_id' => $quote->company_id,
                'quote_id' => $quote->id,
                'customer_id' => $quote->customer_id,
                'contact_id' => $quote->contact_id,
                'sales_rep_id' => $quote->sales_rep_id,
                'customer_po_number' => $customerPoNumber,
                'reference' => $quote->reference,
                'status' => SalesOrderStatus::Confirmed,
                'order_date' => today(),
                'required_by' => $quote->required_by,
                'delivery_address' => $customer->delivery_address,
                'notes' => $quote->notes,
                'show_images' => $quote->show_images ?? true,
            ]);

            foreach ($quote->lines as $line) {
                $order->lines()->create($line->pricedAttributes());
            }

            // Sales orders always show setup separately; totals are rolled up without re-pricing.
            $this->totals->recalculate($order, reprice: false);

            if ($customer->requiresDeposit()) {
                $order->update([
                    'deposit_required' => round((float) $order->total * (float) $customer->deposit_percent / 100, 2),
                    'status' => SalesOrderStatus::AwaitingDeposit,
                ]);
            }

            if ($poFilePath) {
                $order->attachments()->create([
                    'company_id' => $order->company_id,
                    'type' => 'customer_po',
                    'disk' => 'local',
                    'path' => $poFilePath,
                    'original_name' => $poFileName ?: basename($poFilePath),
                    'notes' => $customerPoNumber ? "PO {$customerPoNumber}" : null,
                ]);
            }

            $quote->update(['status' => QuoteStatus::Converted, 'accepted_at' => $quote->accepted_at ?? now()]);

            Activity::log($quote, 'status_change', "Converted to sales order {$order->number}");
            Activity::log($order, 'status_change', "Created from quote {$quote->number}");

            return $order;
        });
    }
}
