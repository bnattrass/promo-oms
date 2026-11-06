<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandingPrice extends Model
{
    public const UNLIMITED_QTY = -1;

    public const NOT_COLOUR_BASED = -2;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'setup' => 'decimal:2',
            'price' => 'decimal:4',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
