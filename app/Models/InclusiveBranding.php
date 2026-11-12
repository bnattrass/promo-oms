<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InclusiveBranding extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'positions' => 'array',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
        ];
    }

    public function label(): string
    {
        return trim($this->description ?: 'Inclusive branding').' (included in item price)';
    }

    public function appliesTo(?string $positionCode, ?string $colourCode): bool
    {
        $positionOk = blank($this->positions) || blank($positionCode)
            || in_array(strtoupper($positionCode), array_map('strtoupper', $this->positions), true);
        $colourOk = blank($this->colour_code) || blank($colourCode) || strcasecmp($this->colour_code, $colourCode) === 0;
        $dateOk = (! $this->valid_from || $this->valid_from->isPast()) && (! $this->valid_to || $this->valid_to->isFuture());

        return $positionOk && $colourOk && $dateOk;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
