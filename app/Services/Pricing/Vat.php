<?php

namespace App\Services\Pricing;

/**
 * VAT the way Amrod calculates it, so our totals match their invoices:
 * round unit price to 2dp -> add VAT -> round 2dp -> multiply by qty -> round once more.
 */
class Vat
{
    public static function inclusiveLineTotal(float $unitPriceExVat, int $quantity, float $rate): float
    {
        $unit = round($unitPriceExVat, 2);
        $unitIncl = round($unit * (1 + $rate), 2);

        return round($unitIncl * $quantity, 2);
    }

    public static function lineVat(float $unitPriceExVat, int $quantity, float $rate): float
    {
        return round(self::inclusiveLineTotal($unitPriceExVat, $quantity, $rate) - round(round($unitPriceExVat, 2) * $quantity, 2), 2);
    }
}
