<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandingMethod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'excluded_colour_codes' => 'array',
            'raw' => 'array',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(BrandingPosition::class, 'branding_position_id');
    }

    public function allowsColour(?string $colourCode): bool
    {
        if (blank($colourCode)) {
            return true;
        }

        return ! in_array(strtoupper($colourCode), array_map('strtoupper', $this->excluded_colour_codes ?? []), true);
    }
}
