<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'stock_incoming' => 'array',
            'raw' => 'array',
            'is_active' => 'boolean',
            'price_synced_at' => 'datetime',
            'stock_synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function label(): string
    {
        return trim(implode(' / ', array_filter([$this->full_code, $this->colour_name, $this->size])));
    }

    public function incomingTotal(): int
    {
        return (int) collect($this->stock_incoming)->sum('qty');
    }
}
