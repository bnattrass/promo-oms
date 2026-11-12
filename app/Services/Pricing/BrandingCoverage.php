<?php

namespace App\Services\Pricing;

use App\Models\BrandingMethod;
use App\Models\BrandingPrice;
use App\Models\Supplier;

/** Which branding codes used on a supplier's active products have no price breaks on file. */
class BrandingCoverage
{
    public function report(Supplier $supplier): array
    {
        $used = BrandingMethod::query()
            ->join('branding_positions', 'branding_positions.id', '=', 'branding_methods.branding_position_id')
            ->join('products', 'products.id', '=', 'branding_positions.product_id')
            ->where('products.supplier_id', $supplier->id)
            ->where('products.is_active', true)
            ->selectRaw('branding_methods.branding_code as code, MIN(branding_methods.name) as method, COUNT(DISTINCT products.id) as products, MIN(products.simple_code) as example')
            ->groupBy('branding_methods.branding_code')
            ->get();

        $priced = BrandingPrice::where('supplier_id', $supplier->id)->distinct()->pluck('branding_code')->flip();

        $missing = $used->reject(fn ($row) => $priced->has($row->code))
            ->sortByDesc('products')
            ->map(fn ($row) => [$row->code, $row->method, (int) $row->products, $row->example])
            ->values()->all();

        return [
            'codes_used' => $used->count(),
            'codes_priced' => $priced->count(),
            'missing' => $missing,
        ];
    }

    /** One-line summary for sync logs; null when everything is covered. */
    public function summary(Supplier $supplier): ?string
    {
        $report = $this->report($supplier);
        if ($report['missing'] === []) {
            return null;
        }
        $top = collect($report['missing'])->take(8)->map(fn ($m) => "{$m[0]} ({$m[2]} products)")->implode(', ');

        return count($report['missing'])." branding code(s) used on products have no prices: {$top}";
    }
}
