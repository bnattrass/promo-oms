<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'required_positions' => 'array',
            'no_co_branding_positions' => 'array',
            'related_codes' => 'array',
            'specs' => 'array',
            'raw' => 'array',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SupplierCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(SupplierBrand::class, 'brand_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function brandingPositions(): HasMany
    {
        return $this->hasMany(BrandingPosition::class);
    }

    /** Inclusive (free / special) branding options for this product, matched on simple code. */
    public function inclusiveBrandingOptions(): Collection
    {
        return InclusiveBranding::query()
            ->where('supplier_id', $this->supplier_id)
            ->where('product_code', $this->simple_code)
            ->get();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function primaryImage(): ?string
    {
        return collect($this->images)->first();
    }

    public function lowestPrice(): ?float
    {
        $min = $this->variants->whereNotNull('cost_price')->min('cost_price');

        return $min === null ? null : (float) $min;
    }

    public function totalStock(): int
    {
        return (int) $this->variants->sum('stock_on_hand');
    }
}
