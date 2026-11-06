<?php

namespace App\Models\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Shared columns and relations for quote_lines and sales_order_lines. */
trait HasPricedLine
{
    public function initializeHasPricedLine(): void
    {
        $this->mergeCasts([
            'brandings' => 'array',
            'unit_cost' => 'decimal:4',
            'branding_unit_cost' => 'decimal:4',
            'setup_cost' => 'decimal:2',
            'extra_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'gp_percent' => 'decimal:2',
            'unit_price_override' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'setup_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'gp_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
        ]);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function isCatalogue(): bool
    {
        return ($this->line_type ?? 'catalogue') === 'catalogue';
    }

    /** Line fields that are copied when a quote becomes a sales order. */
    public function pricedAttributes(): array
    {
        return $this->only([
            'sort', 'line_type', 'unit_price_override', 'supplier_id', 'product_id', 'product_variant_id', 'item_code', 'description', 'quantity',
            'unit_cost', 'brandings', 'branding_unit_cost', 'setup_cost', 'extra_cost', 'total_cost', 'gp_percent',
            'unit_price', 'setup_price', 'line_total', 'gp_amount', 'commission_percent', 'commission_amount',
        ]);
    }
}
