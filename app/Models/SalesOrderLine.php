<?php

namespace App\Models;

use App\Enums\LineStage;
use App\Models\Concerns\HasPricedLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrderLine extends Model
{
    use HasPricedLine;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stage' => LineStage::class];
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function backOrderQuantity(): int
    {
        return max(0, $this->quantity - $this->quantity_received);
    }
}
