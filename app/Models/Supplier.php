<?php

namespace App\Models;

use App\Suppliers\SupplierConnector;
use App\Suppliers\SupplierRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'sync_enabled' => 'boolean',
            'last_full_sync_at' => 'datetime',
            'last_changes_sync_at' => 'datetime',
            'last_stock_sync_at' => 'datetime',
        ];
    }

    public function connector(): ?SupplierConnector
    {
        return $this->driver ? app(SupplierRegistry::class)->make($this) : null;
    }

    public function hasApi(): bool
    {
        return filled($this->driver);
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(SupplierCategory::class);
    }

    public function brands(): HasMany
    {
        return $this->hasMany(SupplierBrand::class);
    }

    public function brandingPrices(): HasMany
    {
        return $this->hasMany(BrandingPrice::class);
    }

    public function inclusiveBrandings(): HasMany
    {
        return $this->hasMany(InclusiveBranding::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
