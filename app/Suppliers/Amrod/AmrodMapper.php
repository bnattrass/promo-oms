<?php

namespace App\Suppliers\Amrod;

use App\Models\BrandingMethod;
use App\Models\BrandingPosition;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierBrand;
use App\Models\SupplierCategory;
use App\Suppliers\Fields as F;

/**
 * Maps one Amrod "product with branding" record onto products / product_variants /
 * branding_positions / branding_methods. Candidate field names are listed most-likely first;
 * adjust them here once a real payload has been inspected (php artisan suppliers:inspect).
 */
class AmrodMapper
{
    private array $categoryIds = [];

    private array $brandIds = [];

    public function __construct(private readonly Supplier $supplier)
    {
        $this->categoryIds = SupplierCategory::where('supplier_id', $supplier->id)->pluck('id', 'external_id')->all();
        $this->brandIds = SupplierBrand::where('supplier_id', $supplier->id)->pluck('id', 'code')->all();
    }

    public function upsertProduct(string $simpleCode, array $row): Product
    {
        $required = array_map('strval', F::list($row, 'requiredBrandingPositions', 'requiredPositions'));
        $noCo = array_map('strval', F::list($row, 'noCoBrandingPositions', 'noCoBranding'));

        $product = Product::updateOrCreate(
            ['supplier_id' => $this->supplier->id, 'simple_code' => $simpleCode],
            [
                'name' => F::string($row, 'productName', 'name', 'title', 'shortDescription') ?? $simpleCode,
                'description' => F::string($row, 'description', 'productDescription', 'longDescription'),
                'type' => str_contains(strtolower((string) F::string($row, 'type', 'productType')), 'gift') ? 'giftset' : 'product',
                'category_id' => $this->categoryId($row),
                'brand_id' => $this->brandId($row),
                'gender' => F::string($row, 'gender'),
                'images' => $this->imageUrls(F::pick($row, 'images', 'productImages', 'image')),
                'branding_guide_url' => $this->firstUrl(F::pick($row, 'fullBrandingGuide', 'brandingGuide', 'brandingTemplates')),
                'required_positions' => $required ?: null,
                'no_co_branding_positions' => $noCo ?: null,
                'related_codes' => array_filter([
                    'companion' => F::list($row, 'companionCodes', 'companionProducts'),
                    'related' => F::list($row, 'relatedCodes', 'relatedProducts'),
                    'matching' => F::list($row, 'matchingCodes', 'matchingProducts'),
                ]) ?: null,
                'specs' => $this->specs($row),
                'raw' => $row,
                'is_active' => true,
                'synced_at' => now(),
            ],
        );

        $this->syncVariants($product, F::list($row, 'variants', 'productVariants', 'items'));
        $this->syncBranding($product, F::list($row, 'brandings', 'brandingPositions', 'positions', 'branding'), $required, $noCo);

        return $product;
    }

    private function syncVariants(Product $product, array $variants): void
    {
        // A product with no variant list is sold as itself.
        if (! $variants) {
            $variants = [['fullCode' => $product->simple_code]];
        }

        $seen = [];
        foreach ($variants as $v) {
            if (! is_array($v)) {
                continue;
            }
            $fullCode = F::string($v, 'fullCode', 'itemCode', 'code', 'sku');
            if (! $fullCode) {
                continue;
            }
            $seen[] = $fullCode;

            ProductVariant::updateOrCreate(
                ['supplier_id' => $this->supplier->id, 'full_code' => $fullCode],
                [
                    'product_id' => $product->id,
                    'colour_code' => F::string($v, 'codeColour', 'colourCode', 'colorCode', 'colour.code'),
                    'colour_name' => F::string($v, 'codeColourName', 'colourName', 'colorName', 'colour.name', 'colour'),
                    'size' => F::string($v, 'codeSizeName', 'codeSize', 'sizeName', 'size'),
                    'image_url' => $this->firstUrl(F::pick($v, 'images', 'image', 'imageUrl')),
                    'raw' => $v,
                    'is_active' => true,
                ],
            );
        }

        ProductVariant::where('product_id', $product->id)->whereNotIn('full_code', $seen)->update(['is_active' => false]);
    }

    /** Positions -> Methods -> excluded colour codes. Rebuilt on each sync of the product. */
    private function syncBranding(Product $product, array $positions, array $required, array $noCo): void
    {
        $product->brandingPositions()->delete(); // cascades to methods

        foreach ($positions as $p) {
            if (! is_array($p)) {
                continue;
            }
            $code = F::string($p, 'positionCode', 'code', 'brandingPositionCode');
            if (! $code) {
                continue;
            }

            $position = BrandingPosition::create([
                'product_id' => $product->id,
                'code' => $code,
                'name' => F::string($p, 'positionName', 'name') ?? $code,
                'multiplier' => max(1, F::int($p, 'positionMultiplier', 'multiplier') ?? 1),
                'is_required' => in_array($code, $required, true),
                'is_no_co_branding' => in_array($code, $noCo, true),
            ]);

            foreach (F::list($p, 'method', 'methods', 'brandingMethods') as $m) {
                $brandingCode = F::string($m, 'brandingCode', 'code', 'printCode');
                if (! $brandingCode) {
                    continue;
                }
                BrandingMethod::create([
                    'branding_position_id' => $position->id,
                    'branding_code' => $brandingCode,
                    'name' => F::string($m, 'brandingName', 'brandingDepartment', 'name') ?? $brandingCode,
                    'max_colours' => F::int($m, 'maxColours', 'numberOfColours', 'maxNumberOfColours', 'colours'),
                    'max_print_size' => $this->printSize($m),
                    'excluded_colour_codes' => array_map('strval', F::list($m, 'exclusions', 'excluded', 'excludedColours', 'excludedColourCodes')) ?: null,
                    'raw' => $m,
                ]);
            }
        }
    }

    private function categoryId(array $row): ?int
    {
        $candidates = [];
        foreach (F::list($row, 'categories', 'productCategories') as $c) {
            $candidates[] = is_array($c) ? F::string($c, 'id', 'categoryId', 'uniqueId', 'categoryCode', 'code') : (string) $c;
        }
        $candidates[] = F::string($row, 'categoryId', 'category.id', 'categoryCode');

        // Prefer the deepest (last) category we know about.
        foreach (array_reverse(array_filter($candidates)) as $id) {
            if (isset($this->categoryIds[$id])) {
                return $this->categoryIds[$id];
            }
        }

        return null;
    }

    private function brandId(array $row): ?int
    {
        $code = F::string($row, 'brandCode', 'brand.code', 'brand', 'brandName');

        return $code ? ($this->brandIds[$code] ?? null) : null;
    }

    private function printSize(array $m): ?string
    {
        $w = F::string($m, 'maxPrintingSizeWidth');
        $h = F::string($m, 'maxPrintingSizeHeight');
        if ($w || $h) {
            return trim(($w ?: '?').' x '.($h ?: '?')).' mm';
        }

        $size = F::pick($m, 'maxPrintSize', 'printSize', 'maxSize');
        if (is_array($size)) {
            $w = F::string($size, 'width', 'w');
            $h = F::string($size, 'height', 'h');

            return $w || $h ? trim("{$w} x {$h}", ' x') : json_encode($size);
        }

        return is_scalar($size) ? (string) $size : null;
    }

    private function specs(array $row): ?array
    {
        $firstVariant = F::list($row, 'variants')[0] ?? [];
        $specs = array_filter([
            'material' => F::string($row, 'material'),
            'fit' => F::string($row, 'fit'),
            'feature' => F::string($row, 'feature'),
            'minimum_order' => F::int($row, 'minimum') ?: null,
            'maximum_order' => F::int($row, 'maximum') ?: null,
            'order_increment' => F::int($row, 'incrementedBy') ?: null,
            'made_to_order' => F::string($row, 'madeToOrderMessage', 'madeToOrder'),
            'country_of_origin' => F::string($row, 'displayCountryOfOrigin'),
            'dimensions' => is_array($firstVariant) ? F::pick($firstVariant, 'productDimension') : null,
            'carton' => is_array($firstVariant) ? F::pick($firstVariant, 'packagingAndDimension') : null,
            'keywords' => F::string($row, 'keywords'),
            'promotion' => F::string($row, 'promotion') === '0' ? null : F::string($row, 'promotion'),
            'introduced' => F::string($row, 'introduced'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $specs ?: null;
    }

    /** Collect every http(s) URL in a nested images structure. */
    private function imageUrls(mixed $images): ?array
    {
        $urls = [];
        self::walk($images, function ($value) use (&$urls) {
            if (is_string($value) && preg_match('#^https?://#i', $value)) {
                $urls[] = $value;
            }
        });

        return $urls ? array_values(array_unique($urls)) : null;
    }

    private static function walk(mixed $value, callable $fn): void
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                self::walk($v, $fn);
            }
        } else {
            $fn($value);
        }
    }

    private function firstUrl(mixed $value): ?string
    {
        return $this->imageUrls($value)[0] ?? null;
    }
}

