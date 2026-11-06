<?php

namespace App\Services\Orders;

use App\Models\ProductVariant;
use App\Models\Quote;

/** Defaults for a new quote line picked from the catalogue. */
class QuoteLineFactory
{
    public function fromVariant(Quote $quote, ProductVariant $variant, int $quantity = 1): array
    {
        $variant->loadMissing('product');
        $quote->loadMissing('company', 'customer', 'salesRep');

        return [
            'supplier_id' => $variant->supplier_id,
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'item_code' => $variant->full_code,
            'description' => trim($variant->product->name.' '.implode(' ', array_filter([$variant->colour_name, $variant->size]))),
            'quantity' => $quantity,
            'unit_cost' => (float) $variant->cost_price,
            'gp_percent' => $this->defaultGp($quote),
            'commission_percent' => (float) ($quote->salesRep?->commission_percent ?? 0),
            'brandings' => [],
        ];
    }

    public function defaultGp(Quote $quote): float
    {
        return (float) ($quote->customer?->default_gp_percent ?? $quote->company?->default_gp_percent ?? 35);
    }
}
