<?php

namespace App\Services\Pricing;

use App\Models\BrandingPrice;
use Illuminate\Support\Collection;

/**
 * Finds the price break for a branding code, quantity and number of colours.
 * Amrod conventions: max_quantity -1 = no upper limit; number_of_colours -2 = not colour based.
 */
class BrandingPriceLookup
{
    /** @var array<string, Collection> */
    private array $cache = [];

    public function find(int $supplierId, string $brandingCode, int $quantity, ?int $colours = null): ?BrandingPrice
    {
        $rows = $this->rows($supplierId, $brandingCode)->filter(
            // -1 / 0 = no minimum, -1 = no maximum (Amrod conventions)
            fn (BrandingPrice $p) => ($p->min_quantity <= 0 || $quantity >= $p->min_quantity)
                && ($p->max_quantity === BrandingPrice::UNLIMITED_QTY || $quantity <= $p->max_quantity)
        );

        if ($rows->isEmpty()) {
            return null;
        }

        // Not colour based (embroidery, laser etc.)
        $flat = $rows->firstWhere('number_of_colours', BrandingPrice::NOT_COLOUR_BASED);
        if ($flat || $colours === null) {
            return $flat ?? $rows->sortBy('number_of_colours')->first();
        }

        // Exact colour count, else the next tier up (e.g. 3 colours priced at the 4-colour break).
        return $rows->firstWhere('number_of_colours', $colours)
            ?? $rows->where('number_of_colours', '>=', $colours)->sortBy('number_of_colours')->first()
            ?? $rows->sortByDesc('number_of_colours')->first();
    }

    /** Distinct colour options available for a branding code (empty = not colour based). */
    public function colourOptions(int $supplierId, string $brandingCode): array
    {
        return $this->rows($supplierId, $brandingCode)
            ->pluck('number_of_colours')
            ->filter(fn ($c) => $c > 0)
            ->unique()->sort()->values()->all();
    }

    private function rows(int $supplierId, string $brandingCode): Collection
    {
        return $this->cache["{$supplierId}:{$brandingCode}"] ??= BrandingPrice::query()
            ->where('supplier_id', $supplierId)
            ->where('branding_code', $brandingCode)
            ->orderBy('min_quantity')
            ->get();
    }
}
