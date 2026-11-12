<?php

namespace App\Suppliers\Amrod;

use App\Models\BrandingPrice;
use App\Models\InclusiveBranding;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\SupplierBrand;
use App\Models\SupplierCategory;
use App\Models\SyncRun;
use App\Suppliers\Fields as F;
use App\Suppliers\SupplierConnector;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Amrod catalogue sync.
 *
 * Field names are read through Fields::pick() with several candidate spellings, and every record's
 * raw JSON is stored. Amrod's docs publish no sample product/stock payload, so after the first real
 * sync run `php artisan suppliers:inspect amrod products` and adjust AmrodMapper if anything is empty.
 */
class AmrodConnector implements SupplierConnector
{
    /** Amrod "Get Updated" ActionType values. */
    public const ACTION_CREATED = 0;

    public const ACTION_UPDATED = 1;

    public const ACTION_REMOVED = 2;

    /** Only per-variant stock (Type 2) may be used for availability. */
    public const STOCK_TYPE_VARIANT = 2;

    private AmrodClient $client;

    public function __construct(private readonly Supplier $supplier)
    {
        $this->client = new AmrodClient($supplier);
    }

    public static function label(): string
    {
        return 'Amrod (Vendor API v2)';
    }

    public static function credentialFields(): array
    {
        return [
            'username' => ['label' => 'API username (email)', 'help' => 'The email Amrod enabled for API access.'],
            'password' => ['label' => 'API password', 'password' => true],
            'customer_code' => ['label' => 'Amrod customer code', 'help' => 'Your Amrod account number.'],
        ];
    }

    public static function modules(): array
    {
        return ['categories', 'brands', 'branding_prices', 'inclusive_brandings', 'products', 'prices', 'stock'];
    }

    public function client(): AmrodClient
    {
        return $this->client;
    }

    public function testConnection(): string
    {
        $this->client->token(fresh: true);

        if (AmrodClient::inMaintenanceWindow()) {
            return 'Login successful. (Amrod data is unavailable until 01:00, so no data was fetched.)';
        }

        $brands = $this->client->get('brands');

        return 'Login successful. Amrod returned '.count($brands).' brands.';
    }

    public function sample(string $module, int $limit = 1): array
    {
        if (str_starts_with($module, 'products')) {
            $out = [];
            foreach ($this->client->stream($module) as $row) {
                $out[] = $row;
                if (count($out) >= $limit) {
                    break;
                }
            }

            return $out;
        }

        return array_slice($this->client->get($module), 0, $limit);
    }

    public function sync(string $module, string $mode, SyncRun $run): SyncRun
    {
        if (! in_array($mode, ['full', 'changes'], true)) {
            throw new InvalidArgumentException("Unknown sync mode [{$mode}]");
        }

        $endpoint = $mode === 'changes' ? "{$module}_changes" : $module;

        // The product feed (with branding) is very large: stream it instead of loading it all.
        if ($module === 'products') {
            $counts = $this->importProducts($this->client->stream($endpoint), $mode, $run);
            $run->update([
                'records_created' => $counts['created'],
                'records_updated' => $counts['updated'],
                'records_deleted' => $counts['deleted'],
            ]);
            $this->supplier->forceFill($mode === 'full' ? ['last_full_sync_at' => now()] : ['last_changes_sync_at' => now()])->save();

            return $run;
        }

        $rows = $this->client->get($endpoint);
        $run->update(['records_received' => count($rows)]);

        $counts = match ($module) {
            'categories' => $this->importCategories($rows),
            'brands' => $this->importBrands($rows),
            'branding_prices' => $this->importBrandingPrices($rows, $mode),
            'inclusive_brandings' => $this->importInclusiveBrandings($rows, $mode),
            'prices' => $this->importPrices($rows, $run),
            'stock' => $this->importStock($rows),
            default => throw new InvalidArgumentException("Amrod connector cannot sync [{$module}]"),
        };

        $run->update([
            'records_created' => $counts['created'] ?? 0,
            'records_updated' => $counts['updated'] ?? 0,
            'records_deleted' => $counts['deleted'] ?? 0,
        ]);

        $stamp = match (true) {
            $module === 'stock' => ['last_stock_sync_at' => now()],
            $mode === 'full' => ['last_full_sync_at' => now()],
            default => ['last_changes_sync_at' => now()],
        };
        $this->supplier->forceFill($stamp)->save();

        return $run;
    }

    // ---------------------------------------------------------------- categories & brands

    private function importCategories(array $rows): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        $walk = function (array $nodes, ?SupplierCategory $parent, string $parentPath) use (&$walk, &$counts) {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                $externalId = F::string($node, 'id', 'categoryId', 'uniqueId', 'categoryCode', 'code');
                $name = F::string($node, 'categoryName', 'name', 'title');
                if (! $externalId || ! $name) {
                    continue;
                }

                if (F::int($node, 'actionType') === self::ACTION_REMOVED) {
                    $counts['deleted'] += SupplierCategory::where('supplier_id', $this->supplier->id)
                        ->where('external_id', $externalId)->delete();

                    continue;
                }

                $path = F::string($node, 'categoryPath', 'fullPath', 'path') ?? trim("{$parentPath}/{$name}", '/');
                $category = SupplierCategory::updateOrCreate(
                    ['supplier_id' => $this->supplier->id, 'external_id' => $externalId],
                    [
                        'parent_id' => $parent?->id,
                        'code' => F::string($node, 'categoryCode', 'code'),
                        'name' => $name,
                        'path' => $path,
                        'image_url' => F::string($node, 'image', 'imageUrl', 'categoryImage'),
                    ],
                );
                $category->wasRecentlyCreated ? $counts['created']++ : $counts['updated']++;

                $children = F::pick($node, 'children', 'subCategories', 'categories', 'childCategories');
                if (is_array($children) && $children) {
                    $walk($children, $category, $path);
                }
            }
        };

        DB::transaction(fn () => $walk($rows, null, ''));

        return $counts;
    }

    private function importBrands(array $rows): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        foreach ($rows as $row) {
            $code = F::string($row, 'code', 'brandCode', 'id');
            if (! $code) {
                continue;
            }
            if (F::int($row, 'actionType') === self::ACTION_REMOVED) {
                $counts['deleted'] += SupplierBrand::where('supplier_id', $this->supplier->id)->where('code', $code)->delete();

                continue;
            }
            $brand = SupplierBrand::updateOrCreate(
                ['supplier_id' => $this->supplier->id, 'code' => $code],
                [
                    'name' => F::string($row, 'name', 'brandName') ?? $code,
                    'image_url' => F::string($row, 'image', 'imageUrl', 'logo', 'brandImage'),
                ],
            );
            $brand->wasRecentlyCreated ? $counts['created']++ : $counts['updated']++;
        }

        return $counts;
    }

    // ---------------------------------------------------------------- branding lookups

    /**
     * Payload (documented): [{brandingCode, brandingMethod, data: [{printCode, minQuantity (-1 = no min), maxQuantity(-1 = no max),
     * numberOfColours(-2 = not colour based), setup, price}]}]
     */
    private function importBrandingPrices(array $rows, string $mode): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        DB::transaction(function () use ($rows, $mode, &$counts) {
            if ($mode === 'full') {
                $counts['deleted'] = BrandingPrice::where('supplier_id', $this->supplier->id)->delete();
            }

            foreach ($rows as $row) {
                $code = F::string($row, 'brandingCode');
                if (! $code) {
                    continue;
                }

                // A changed branding code is replaced as a whole set of price breaks.
                if ($mode === 'changes') {
                    $counts['deleted'] += BrandingPrice::where('supplier_id', $this->supplier->id)
                        ->where('branding_code', $code)->delete();
                    if (F::int($row, 'actionType') === self::ACTION_REMOVED) {
                        continue;
                    }
                }

                $insert = [];
                foreach (F::list($row, 'data', 'prices', 'priceBreaks') as $break) {
                    $insert[] = [
                        'supplier_id' => $this->supplier->id,
                        'branding_code' => $code,
                        'branding_method' => F::string($row, 'brandingMethod'),
                        'print_code' => F::string($break, 'printCode'),
                        'min_quantity' => F::int($break, 'minQuantity', 'minQty') ?? 1,
                        'max_quantity' => F::int($break, 'maxQuantity', 'maxQty') ?? BrandingPrice::UNLIMITED_QTY,
                        'number_of_colours' => F::int($break, 'numberOfColours', 'colours') ?? BrandingPrice::NOT_COLOUR_BASED,
                        'setup' => F::float($break, 'setup', 'setupCost') ?? 0,
                        'price' => F::float($break, 'price', 'unitPrice') ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                foreach (array_chunk($insert, 500) as $chunk) {
                    BrandingPrice::insert($chunk);
                }
                $counts['created'] += count($insert);
            }
        });

        return $counts;
    }

    /**
     * Inclusive branding (live payload): one flat row per product colour, e.g.
     * {inclusiveBrandingId, inclusiveBrandingName, brandingPositions: "A^B^C", brandingCode: "PA",
     *  brandingReplacementCode: "PA-FREE", numberOfColours: 1, simpleCode, colour: "BL", minQuantity, validFrom, validTo}
     */
    private function importInclusiveBrandings(array $rows, string $mode): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        // Rows may also arrive grouped per product with a nested list.
        $flat = [];
        foreach ($rows as $row) {
            $nested = F::pick($row, 'inclusiveBranding', 'inclusiveBrandings');
            if (is_array($nested) && array_is_list($nested)) {
                foreach ($nested as $n) {
                    $flat[] = (is_array($n) ? $n : []) + ['simpleCode' => F::string($row, 'simpleCode', 'productCode'), 'actionType' => F::int($row, 'actionType')];
                }
            } else {
                $flat[] = $row;
            }
        }

        DB::transaction(function () use ($flat, $mode, &$counts) {
            if ($mode === 'full') {
                $counts['deleted'] = InclusiveBranding::where('supplier_id', $this->supplier->id)->delete();
            } else {
                // Changes: replace everything for each product mentioned.
                $codes = array_values(array_unique(array_filter(array_map(fn ($r) => F::string($r, 'simpleCode', 'productCode', 'itemCode'), $flat))));
                foreach (array_chunk($codes, 500) as $chunk) {
                    $counts['deleted'] += InclusiveBranding::where('supplier_id', $this->supplier->id)->whereIn('product_code', $chunk)->delete();
                }
            }

            $now = now();
            $insert = [];
            foreach ($flat as $r) {
                $productCode = F::string($r, 'simpleCode', 'productCode', 'itemCode');
                if (! $productCode || F::int($r, 'actionType') === self::ACTION_REMOVED) {
                    continue;
                }
                $positions = array_values(array_filter(array_map('trim', preg_split('/[\^,]/', (string) F::string($r, 'brandingPositions', 'positions')))));

                $insert[] = [
                    'supplier_id' => $this->supplier->id,
                    'external_id' => F::string($r, 'inclusiveBrandingId', 'id'),
                    'product_code' => $productCode,
                    'colour_code' => F::string($r, 'colour', 'colourCode', 'codeColour'),
                    'position_code' => F::string($r, 'positionCode', 'brandingPositionCode'),
                    'positions' => $positions ? json_encode($positions) : null,
                    'branding_code' => F::string($r, 'brandingCode'),
                    'replacement_code' => F::string($r, 'brandingReplacementCode', 'replacementCode', 'replacementBrandingCode'),
                    'number_of_colours' => F::int($r, 'numberOfColours'),
                    'min_quantity' => max(1, F::int($r, 'minQuantity') ?? 1),
                    'valid_from' => ($v = F::string($r, 'validFrom')) ? \Illuminate\Support\Carbon::parse($v) : null,
                    'valid_to' => ($v = F::string($r, 'validTo')) ? \Illuminate\Support\Carbon::parse($v)->min('2099-12-31') : null,
                    'description' => F::string($r, 'inclusiveBrandingName', 'description', 'name'),
                    'raw' => null, // the structured columns hold everything needed; keeps the table small
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (count($insert) >= 500) {
                    InclusiveBranding::insert($insert);
                    $counts['created'] += count($insert);
                    $insert = [];
                }
            }
            if ($insert) {
                InclusiveBranding::insert($insert);
                $counts['created'] += count($insert);
            }
        });

        return $counts;
    }

    // ---------------------------------------------------------------- products

    private function importProducts(iterable $rows, string $mode, SyncRun $run): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $mapper = new AmrodMapper($this->supplier);
        $seen = [];
        $received = 0;
        $batch = [];

        $flush = function () use (&$batch, &$counts, $mapper, $run, &$received) {
            if (! $batch) {
                return;
            }
            DB::transaction(function () use ($batch, &$counts, $mapper) {
                foreach ($batch as [$simpleCode, $row]) {
                    if (F::int($row, 'actionType') === self::ACTION_REMOVED) {
                        $counts['deleted'] += Product::where('supplier_id', $this->supplier->id)
                            ->where('simple_code', $simpleCode)->update(['is_active' => false]);
                        ProductVariant::where('supplier_id', $this->supplier->id)
                            ->whereHas('product', fn ($q) => $q->where('simple_code', $simpleCode))
                            ->update(['is_active' => false]);

                        continue;
                    }
                    $product = $mapper->upsertProduct($simpleCode, $row);
                    $product->wasRecentlyCreated ? $counts['created']++ : $counts['updated']++;
                }
            });
            $batch = [];
            $run->update(['records_received' => $received] + array_combine(
                ['records_created', 'records_updated', 'records_deleted'],
                [$counts['created'], $counts['updated'], $counts['deleted']],
            ));
            gc_collect_cycles();
        };

        foreach ($rows as $row) {
            $received++;
            $simpleCode = F::string($row, 'simpleCode', 'productCode', 'code');
            if (! $simpleCode) {
                continue;
            }
            $seen[] = $simpleCode;
            $batch[] = [$simpleCode, $row];
            if (count($batch) >= 50) {
                $flush();
            }
        }
        $flush();
        $run->update(['records_received' => $received]);

        // A full import is the whole live catalogue: anything not seen is no longer sold.
        if ($mode === 'full' && $seen) {
            $counts['deleted'] += Product::where('supplier_id', $this->supplier->id)
                ->where('is_active', true)
                ->whereNotIn('simple_code', $seen)
                ->update(['is_active' => false]);
        }

        return $counts;
    }

    // ---------------------------------------------------------------- prices & stock

    /** Prices match on the variant full code only. Ex VAT, at the account's tier. */
    private function importPrices(array $rows, ?SyncRun $run = null): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $noPriceField = 0;
        $noMatch = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$counts, &$noPriceField, &$noMatch) {
                foreach ($chunk as $row) {
                    $fullCode = F::string($row, 'fullCode', 'itemCode', 'productCode', 'code', 'sku');
                    if (! $fullCode) {
                        continue;
                    }
                    $removed = F::int($row, 'actionType') === self::ACTION_REMOVED;
                    $price = F::float($row, 'price', 'sellingPrice', 'unitPrice', 'netPrice', 'customerPrice', 'tierPrice', 'priceExVat', 'amount');

                    // Never blank an existing price because a field name didn't match.
                    if (! $removed && $price === null) {
                        $noPriceField++;

                        continue;
                    }

                    $updated = ProductVariant::where('supplier_id', $this->supplier->id)
                        ->where('full_code', $fullCode)
                        ->update([
                            'cost_price' => $removed ? null : $price,
                            'price_synced_at' => now(),
                        ]);
                    if ($updated === 0) {
                        $noMatch++;
                    }
                    if ($removed) {
                        $counts['deleted'] += $updated;
                    } else {
                        $counts['updated'] += $updated;
                    }
                }
            });
        }

        $warnings = array_filter([
            $noPriceField ? "{$noPriceField} rows had no recognisable price field - run: php artisan suppliers:inspect AMROD prices" : null,
            $noMatch ? "{$noMatch} price rows matched no product variant (import products first)" : null,
        ]);
        if ($warnings && $run) {
            $run->update(['message' => implode('; ', $warnings)]);
        }

        return $counts;
    }

    /** Only per-variant (Type 2) rows are used; incoming rows carry qty + ETA. */
    private function importStock(array $rows): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        $byCode = [];
        foreach ($rows as $row) {
            $type = F::int($row, 'stockType', 'type');
            if ($type !== null && $type !== self::STOCK_TYPE_VARIANT) {
                continue;
            }
            $fullCode = F::string($row, 'fullCode', 'itemCode', 'productCode', 'code');
            if (! $fullCode) {
                continue;
            }

            $byCode[$fullCode] ??= ['on_hand' => 0, 'incoming' => []];
            $byCode[$fullCode]['on_hand'] = F::int($row, 'stock', 'currentStock', 'stockLevel', 'quantity', 'qty', 'available')
                ?? $byCode[$fullCode]['on_hand'];

            $incomingList = F::pick($row, 'incomingStock', 'incoming', 'stockIncoming');
            if (is_array($incomingList) && array_is_list($incomingList)) {
                foreach ($incomingList as $in) {
                    $byCode[$fullCode]['incoming'][] = [
                        'qty' => F::int($in, 'quantity', 'qty', 'incomingQuantity', 'stock') ?? 0,
                        'eta' => F::string($in, 'eta', 'etaDate', 'expectedDate', 'date'),
                    ];
                }
            } elseif (($qty = F::int($row, 'incomingQuantity', 'incomingStock', 'incoming')) !== null) {
                $byCode[$fullCode]['incoming'][] = ['qty' => $qty, 'eta' => F::string($row, 'eta', 'etaDate', 'incomingDate')];
            }
        }

        foreach (array_chunk($byCode, 500, true) as $chunk) {
            DB::transaction(function () use ($chunk, &$counts) {
                foreach ($chunk as $fullCode => $stock) {
                    $counts['updated'] += ProductVariant::where('supplier_id', $this->supplier->id)
                        ->where('full_code', $fullCode)
                        ->update([
                            'stock_on_hand' => max(0, $stock['on_hand']),
                            'stock_incoming' => json_encode(array_values(array_filter($stock['incoming'], fn ($i) => $i['qty'] > 0))),
                            'stock_synced_at' => now(),
                        ]);
                }
            });
        }

        return $counts;
    }
}
