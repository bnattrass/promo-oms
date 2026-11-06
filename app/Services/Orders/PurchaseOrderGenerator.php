<?php

namespace App\Services\Orders;

use App\Enums\LineStage;
use App\Enums\PurchaseOrderStatus;
use App\Models\Activity;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Services\Pricing\DocumentTotals;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Sales order -> one draft purchase order per supplier, for lines not yet on a PO. */
class PurchaseOrderGenerator
{
    public function __construct(private readonly DocumentTotals $totals) {}

    /** @return Collection<int, PurchaseOrder> */
    public function generate(SalesOrder $order): Collection
    {
        return DB::transaction(function () use ($order) {
            $lines = $order->lines()
                ->whereNotNull('supplier_id')
                ->whereDoesntHave('purchaseOrderLines')
                ->get();

            return $lines->groupBy('supplier_id')->map(function (Collection $supplierLines, int $supplierId) use ($order) {
                $po = PurchaseOrder::create([
                    'company_id' => $order->company_id,
                    'supplier_id' => $supplierId,
                    'sales_order_id' => $order->id,
                    'status' => PurchaseOrderStatus::Draft,
                    'order_date' => today(),
                    'expected_date' => $order->required_by,
                    'notes' => trim("Customer: {$order->customer?->name}\nOur ref: {$order->number}".
                        ($order->reference ? " / {$order->reference}" : '')),
                ]);

                foreach ($supplierLines as $line) {
                    $po->lines()->create([
                        'sales_order_line_id' => $line->id,
                        'product_variant_id' => $line->product_variant_id,
                        'item_code' => $line->item_code,
                        'description' => $line->description,
                        'brandings' => collect($line->brandings)->map(fn ($b) => array_intersect_key($b, array_flip([
                            'position_code', 'position_name', 'branding_code', 'priced_code', 'print_code',
                            'method_name', 'colours', 'placements', 'inclusive', 'unit_cost', 'setup_cost',
                        ])))->values()->all(),
                        'quantity' => $line->quantity,
                        'unit_cost' => round((float) $line->unit_cost + (float) $line->branding_unit_cost, 4),
                        'setup_cost' => $line->setup_cost,
                    ]);
                    $line->update(['stage' => LineStage::Ordered]);
                }

                $this->totals->recalculatePurchaseOrder($po);
                Activity::log($order, 'purchase_order', "Purchase order {$po->number} created for {$po->supplier->name}");

                return $po;
            })->values();
        });
    }
}
