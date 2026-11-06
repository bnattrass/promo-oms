<?php

namespace App\Models;

use App\Models\Concerns\HasPricedLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteLine extends Model
{
    use HasPricedLine;

    protected $guarded = [];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
