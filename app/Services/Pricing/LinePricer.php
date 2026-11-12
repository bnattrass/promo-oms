<?php

namespace App\Services\Pricing;

/**
 * Prices one quote / sales-order line.
 *
 * Input (array, as stored on the line):
 *   quantity, unit_cost (product cost ex VAT), extra_cost, gp_percent, commission_percent,
 *   unit_price_override (optional: fix the sell price and back-calculate GP),
 *   brandings: [{position_code, position_name, branding_code, method_name, colours, placements,
 *                inclusive (bool), replacement_code}]
 *
 * Costs:  branding unit cost = sum(price x placements), setup = sum(setup x placements)
 *         total cost = (unit cost + branding unit cost) x qty + setup + extra
 * Sell:   cost / (1 - GP%); setups are priced as their own amount when shown separately.
 */
class LinePricer
{
    public function __construct(private readonly BrandingPriceLookup $lookup) {}

    public function price(array $line, ?int $supplierId, bool $setupSeparately = true): array
    {
        $qty = max(1, (int) ($line['quantity'] ?? 1));
        $unitCost = (float) ($line['unit_cost'] ?? 0);
        $extraCost = (float) ($line['extra_cost'] ?? 0);
        $gp = min(95, max(0, (float) ($line['gp_percent'] ?? 0))) / 100;
        $commissionPct = (float) ($line['commission_percent'] ?? 0);

        $brandingUnit = 0.0;
        $setup = 0.0;
        $brandings = [];

        foreach ($line['brandings'] ?? [] as $b) {
            // Skip empty rows; a row without a supplier code still counts when costs were entered by hand.
            if (blank($b['branding_code'] ?? null) && blank($b['unit_cost_override'] ?? null) && blank($b['setup_override'] ?? null)) {
                continue;
            }
            $placements = max(1, (int) ($b['placements'] ?? 1));
            $colours = filled($b['colours'] ?? null) ? (int) $b['colours'] : null;
            $code = ! empty($b['inclusive']) && filled($b['replacement_code'] ?? null) ? $b['replacement_code'] : ($b['branding_code'] ?? null);

            $break = $supplierId && filled($code) ? $this->lookup->find($supplierId, $code, $qty, $colours) : null;

            // Manual override (e.g. a supplier without an API, or a negotiated price)
            $bUnit = isset($b['unit_cost_override']) && $b['unit_cost_override'] !== '' ? (float) $b['unit_cost_override'] : (float) ($break?->price ?? 0);
            $bSetup = isset($b['setup_override']) && $b['setup_override'] !== '' ? (float) $b['setup_override'] : (float) ($break?->setup ?? 0);

            // Inclusive branding (a special, or a method Amrod flags as included in the item price)
            // with no price break on file costs nothing extra.
            if ((! empty($b['inclusive']) || ! empty($b['method_inclusive'])) && ! $break && ! isset($b['unit_cost_override'])) {
                $bUnit = 0.0;
                $bSetup = 0.0;
            }

            $brandingUnit += $bUnit * $placements;
            $setup += $bSetup * $placements;

            $brandings[] = array_merge($b, [
                'priced_code' => $code,
                'print_code' => $break?->print_code,
                'unit_cost' => round($bUnit, 4),
                'setup_cost' => round($bSetup, 2),
                'price_found' => (bool) $break,
            ]);
        }

        $variableCost = ($unitCost + $brandingUnit) * $qty + $extraCost;
        $totalCost = round($variableCost + $setup, 2);
        $divisor = 1 - $gp;

        if (filled($line['unit_price_override'] ?? null)) {
            $unitPrice = round((float) $line['unit_price_override'], 2);
            $setupPrice = $setupSeparately ? round($setup / $divisor, 2) : 0.0;
        } elseif ($setupSeparately) {
            $unitPrice = round($variableCost / $divisor / $qty, 2);
            $setupPrice = round($setup / $divisor, 2);
        } else {
            $unitPrice = round(($variableCost + $setup) / $divisor / $qty, 2);
            $setupPrice = 0.0;
        }

        $lineTotal = round($unitPrice * $qty + $setupPrice, 2);
        $gpAmount = round($lineTotal - $totalCost, 2);

        return [
            'quantity' => $qty,
            'brandings' => $brandings,
            'branding_unit_cost' => round($brandingUnit, 4),
            'setup_cost' => round($setup, 2),
            'total_cost' => $totalCost,
            'gp_percent' => $lineTotal > 0 ? round($gpAmount / $lineTotal * 100, 2) : 0,
            'unit_price' => $unitPrice,
            'setup_price' => $setupPrice,
            'line_total' => $lineTotal,
            'gp_amount' => $gpAmount,
            'commission_amount' => round(max(0, $gpAmount) * $commissionPct / 100, 2),
        ];
    }
}
