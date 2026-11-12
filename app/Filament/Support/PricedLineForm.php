<?php

namespace App\Filament\Support;

use App\Enums\LineType;
use App\Models\BrandingMethod;
use App\Models\BrandingPosition;
use App\Models\InclusiveBranding;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Pricing\BrandingPriceLookup;
use App\Services\Pricing\LinePricer;
use App\Services\Pricing\Vat;
use App\Suppliers\Fields;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The line editor shared by quote lines and sales-order lines:
 * pick a catalogue variant, choose branding per position, set quantity and GP%, see live pricing.
 */
class PricedLineForm
{
    public static function components(Quote|SalesOrder $owner): array
    {
        $setupSeparately = $owner instanceof Quote ? (bool) $owner->show_setup_separately : true;

        return [
            Section::make('Item')->columns(4)->schema([
                Select::make('product_variant_id')
                    ->label('Catalogue item')
                    ->placeholder('Search by code or name, or leave empty for a custom item')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => static::searchVariants($search))
                    ->getOptionLabelUsing(fn ($value) => static::variantLabel(ProductVariant::with('product')->find($value)))
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                        $variant = $state ? ProductVariant::with('product')->find($state) : null;
                        $set('supplier_id', $variant?->supplier_id);
                        $set('product_id', $variant?->product_id);
                        $set('item_code', $variant?->full_code);
                        $set('description', $variant ? trim($variant->product->name.' '.implode(' ', array_filter([$variant->colour_name, $variant->size]))) : null);
                        $set('unit_cost', $variant?->cost_price);
                        $set('brandings', []);
                    })
                    ->columnSpan(4),
                Hidden::make('supplier_id'),
                Hidden::make('product_id'),
                TextInput::make('item_code')->label('Code'),
                TextInput::make('description')->required()->columnSpan(3),
                Text::make(fn (Get $get) => static::stockNote($get('product_variant_id')))->columnSpan(4),
                Text::make(fn (Get $get) => static::guideLink($get('product_id')))->columnSpan(4),
            ]),

            Section::make('Branding')
                ->description('Positions and methods come from the supplier. Prices are looked up by branding code, quantity and colours.')
                ->schema([
                    Repeater::make('brandings')
                        ->label('')
                        ->addActionLabel('Add branding position')
                        ->defaultItems(0)
                        ->live()
                        ->columns(6)
                        ->itemLabel(fn (array $state) => trim(($state['position_name'] ?? '').' '.($state['method_name'] ?? '')) ?: null)
                        ->schema([
                            Select::make('position_code')->label('Position')->columnSpan(2)
                                ->options(fn (Get $get) => static::positionOptions($get('../../product_id')))
                                ->live()
                                ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                                    $position = static::position($get('../../product_id'), $state);
                                    $set('position_name', $position?->name);
                                    $set('method_choice', null);
                                    static::applyMethodChoice(null, $set, $get);
                                    $set('placements', 1);
                                })
                                ->required(fn (Get $get) => filled($get('../../product_id'))),
                            Select::make('method_choice')->label('Method')->columnSpan(2)
                                ->options(fn (Get $get) => static::methodOptions(
                                    $get('../../product_id'), $get('position_code'), $get('../../product_variant_id'), $get('../../supplier_id')))
                                ->disableOptionWhen(fn (string $value, Get $get) => static::inclusiveBelowMinimum($value, $get('../../quantity')))
                                ->afterStateHydrated(function (Select $component, $state, Get $get) {
                                    if (blank($state) && filled($get('branding_code'))) {
                                        $component->state($get('inclusive') && $get('inclusive_id')
                                            ? 'INC:'.$get('inclusive_id').':'.$get('branding_code')
                                            : $get('branding_code'));
                                    }
                                })
                                ->live()
                                ->afterStateUpdated(fn (?string $state, Set $set, Get $get) => static::applyMethodChoice($state, $set, $get))
                                ->required(fn (Get $get) => filled($get('../../product_id')))
                                ->hidden(fn (Get $get) => blank($get('../../product_id'))),
                            Hidden::make('branding_code')->visible(fn (Get $get) => filled($get('../../product_id'))),
                            TextInput::make('branding_code')->label('Branding code')->columnSpan(2)
                                ->visible(fn (Get $get) => blank($get('../../product_id'))),
                            Select::make('colours')->label('Colours')
                                ->options(fn (Get $get) => static::colourOptions($get('../../supplier_id'), $get('branding_code')))
                                ->placeholder('n/a')
                                ->disabled(fn (Get $get) => (bool) $get('inclusive'))
                                ->dehydrated()
                                ->helperText(fn (Get $get) => $get('inclusive') ? 'Set by the inclusive offer' : null)
                                ->live(),
                            TextInput::make('placements')->label('Placements')->numeric()->minValue(1)->default(1)
                                ->maxValue(fn (Get $get) => static::position($get('../../product_id'), $get('position_code'))?->multiplier ?? 9)
                                ->live(onBlur: true),
                            TextInput::make('unit_cost_override')->label('Unit cost override')->numeric()->prefix('R')
                                ->helperText('Only if not priced by the supplier')->live(onBlur: true),
                            TextInput::make('setup_override')->label('Setup override')->numeric()->prefix('R')->live(onBlur: true),
                            Text::make(fn (Get $get) => static::templateLink($get('../../product_id'), $get('position_code')))->columnSpan(6),
                            Hidden::make('position_name'),
                            Hidden::make('method_name'),
                            Hidden::make('replacement_code'),
                            Hidden::make('method_inclusive'),
                            Hidden::make('inclusive'),
                            Hidden::make('inclusive_id'),
                        ]),
                ]),

            Section::make('Pricing')->columns(5)->schema([
                TextInput::make('quantity')->numeric()->minValue(1)->default(1)->required()->live(onBlur: true),
                TextInput::make('unit_cost')->label('Unit cost (ex VAT)')->numeric()->prefix('R')->default(0)->live(onBlur: true),
                TextInput::make('extra_cost')->label('Extra cost (courier etc.)')->numeric()->prefix('R')->default(0)->live(onBlur: true),
                TextInput::make('gp_percent')->label('GP %')->numeric()->suffix('%')->required()->live(onBlur: true)
                    ->default(fn () => $owner instanceof Quote ? (float) ($owner->customer?->default_gp_percent ?? $owner->company?->default_gp_percent ?? 35) : 35),
                TextInput::make('commission_percent')->label('Commission %')->numeric()->suffix('%')
                    ->default(fn () => (float) ($owner->salesRep?->commission_percent ?? 0))->live(onBlur: true),
                TextInput::make('unit_price_override')->label('Fixed sell price / unit')->numeric()->prefix('R')
                    ->helperText('Optional. Overrides GP% - GP is then worked out from this price.')->live(onBlur: true)->columnSpan(2),
                Hidden::make('line_type')->default('catalogue'),
                Grid::make(1)->columnSpanFull()->schema([
                    Text::make(fn (Get $get) => static::preview($get, $owner, $setupSeparately)),
                ]),
            ]),
        ];
    }

    /** Simple form for non-catalogue lines: courier, design work, setup, services. */
    public static function customComponents(Quote|SalesOrder $owner): array
    {
        $setupSeparately = $owner instanceof Quote ? (bool) $owner->show_setup_separately : true;

        return [
            Section::make()->columns(4)->schema([
                Select::make('line_type')->label('Type')->options(LineType::customOptions())->default(LineType::Courier->value)->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                        if (blank($get('description')) && $state) {
                            $set('description', LineType::from($state)->getLabel());
                        }
                    }),
                TextInput::make('description')->required()->columnSpan(3)
                    ->placeholder('e.g. Courier to Johannesburg, Logo vectorising, Artwork design (2 hours)'),
                TextInput::make('item_code')->label('Code (optional)'),
                TextInput::make('quantity')->numeric()->minValue(1)->default(1)->required()->live(onBlur: true),
                TextInput::make('unit_cost')->label('Your cost / unit (ex VAT)')->numeric()->prefix('R')->default(0)->live(onBlur: true)
                    ->helperText('What it costs you, e.g. the courier quote. 0 for own work.'),
                TextInput::make('gp_percent')->label('GP %')->numeric()->suffix('%')->required()->live(onBlur: true)
                    ->default(fn () => $owner instanceof Quote ? (float) ($owner->customer?->default_gp_percent ?? $owner->company?->default_gp_percent ?? 35) : 35),
                TextInput::make('unit_price_override')->label('Fixed sell price / unit')->numeric()->prefix('R')->live(onBlur: true)
                    ->helperText('Use this for own work (e.g. R650 per hour). Overrides GP%.')->columnSpan(2),
                TextInput::make('commission_percent')->label('Commission %')->numeric()->suffix('%')
                    ->default(fn () => (float) ($owner->salesRep?->commission_percent ?? 0))->live(onBlur: true),
                Hidden::make('extra_cost')->default(0),
                Grid::make(1)->columnSpanFull()->schema([
                    Text::make(fn (Get $get) => static::preview($get, $owner, $setupSeparately)),
                ]),
            ]),
        ];
    }

    /** Price the line from submitted form data. Returns data ready to save. */
    public static function priceData(array $data, Quote|SalesOrder $owner): array
    {
        $setupSeparately = $owner instanceof Quote ? (bool) $owner->show_setup_separately : true;
        $data['brandings'] = array_values($data['brandings'] ?? []);
        $priced = app(LinePricer::class)->price($data, $data['supplier_id'] ?? null, $setupSeparately);

        // With GP pricing keep the GP% the user typed; with a fixed sell price store the GP% it achieves.
        if (blank($data['unit_price_override'] ?? null)) {
            unset($priced['gp_percent']);
        }

        return array_merge($data, $priced, [
            'extra_cost' => (float) ($data['extra_cost'] ?? 0),
            'unit_cost' => (float) ($data['unit_cost'] ?? 0),
            'commission_percent' => (float) ($data['commission_percent'] ?? 0),
            'line_type' => $data['line_type'] ?? 'catalogue',
            'unit_price_override' => filled($data['unit_price_override'] ?? null) ? (float) $data['unit_price_override'] : null,
        ]);
    }

    /** Re-price an existing line after an inline change (GP%, qty, fixed price). */
    public static function repriceRecord(Model $line, array $changes, Quote|SalesOrder $owner): void
    {
        $data = array_merge($line->only([
            'line_type', 'supplier_id', 'product_id', 'product_variant_id', 'item_code', 'description', 'quantity',
            'unit_cost', 'extra_cost', 'gp_percent', 'unit_price_override', 'commission_percent', 'brandings',
        ]), $changes);

        $priced = static::priceData($data, $owner);

        $line->update(array_intersect_key($priced, array_flip([
            'quantity', 'gp_percent', 'unit_price_override', 'brandings', 'branding_unit_cost', 'setup_cost', 'total_cost',
            'unit_price', 'setup_price', 'line_total', 'gp_amount', 'commission_amount',
        ])));
    }

    // ------------------------------------------------------------------ branding guide links

    /** Link to the supplier's full branding guide PDF for the product. */
    public static function guideLink(?string $productId): ?HtmlString
    {
        $product = $productId ? once(fn () => Product::find($productId, ['id', 'branding_guide_url', 'raw'])) : null;
        if (! $product) {
            return null;
        }

        $links = [];
        if ($product->branding_guide_url) {
            $links[] = static::link($product->branding_guide_url, 'Open branding guide (PDF)');
        }
        if ($count = count(static::templates($product))) {
            $links[] = '<span style="opacity:.7">'.$count.' position template'.($count > 1 ? 's' : '').' - linked under each branding position below</span>';
        }

        return $links ? new HtmlString(implode(' &nbsp;·&nbsp; ', $links)) : new HtmlString('<span style="opacity:.6">No branding guide from the supplier for this item.</span>');
    }

    /** Link to the artwork template for one branding position. */
    public static function templateLink(?string $productId, ?string $positionCode): ?HtmlString
    {
        if (! $productId || ! $positionCode) {
            return null;
        }
        $product = once(fn () => Product::find($productId, ['id', 'branding_guide_url', 'raw']));
        $template = $product ? collect(static::templates($product))->first(fn ($t) => strcasecmp((string) ($t['position'] ?? ''), $positionCode) === 0) : null;

        return $template && ! empty($template['url'])
            ? new HtmlString(static::link($template['url'], 'Artwork template for position '.e($positionCode).(! empty($template['name']) ? ' ('.e($template['name']).')' : '')))
            : null;
    }

    /** @return array<int, array{position?: string, name?: string, url?: string}> */
    private static function templates(Product $product): array
    {
        return array_values(array_filter(Fields::list($product->raw, 'brandingTemplates'), 'is_array'));
    }

    private static function link(string $url, string $label): string
    {
        $href = e(str_replace(' ', '%20', $url));

        return '<a href="'.$href.'" target="_blank" rel="noopener" style="color:rgb(var(--primary-600));text-decoration:underline">'.$label.' &#8599;</a>';
    }

    // ------------------------------------------------------------------ helpers

    public static function searchVariants(string $search): array
    {
        return ProductVariant::query()
            ->with('product')
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('full_code', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")->orWhere('simple_code', 'like', "%{$search}%")))
            ->orderBy('full_code')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (ProductVariant $v) => [$v->id => static::variantLabel($v)])
            ->all();
    }

    public static function variantLabel(?ProductVariant $v): ?string
    {
        if (! $v) {
            return null;
        }

        return implode(' | ', array_filter([
            $v->full_code,
            $v->product?->name,
            trim($v->colour_name.' '.$v->size) ?: null,
            $v->cost_price !== null ? 'R '.number_format((float) $v->cost_price, 2) : 'no price',
            number_format($v->stock_on_hand).' in stock',
        ]));
    }

    public static function stockNote(?string $variantId): ?HtmlString
    {
        $v = $variantId ? ProductVariant::find($variantId) : null;
        if (! $v) {
            return null;
        }
        $incoming = collect($v->stock_incoming)->map(fn ($i) => number_format($i['qty']).($i['eta'] ? ' due '.substr($i['eta'], 0, 10) : ''))->implode(', ');

        return new HtmlString(e(number_format($v->stock_on_hand).' in stock'.($incoming ? " · incoming: {$incoming}" : '')
            .($v->stock_synced_at ? ' · as of '.$v->stock_synced_at->diffForHumans() : '')));
    }

    public static function position(?string $productId, ?string $code): ?BrandingPosition
    {
        if (! $productId || ! $code) {
            return null;
        }

        return once(fn () => BrandingPosition::with('methods')->where('product_id', $productId)->where('code', $code)->first());
    }

    public static function positionOptions(?string $productId): array
    {
        if (! $productId) {
            return [];
        }

        return BrandingPosition::where('product_id', $productId)->orderBy('code')->get()
            ->mapWithKeys(fn ($p) => [$p->code => "{$p->code} - {$p->name}".($p->is_required ? ' (required)' : '').($p->is_no_co_branding ? ' (no co-branding)' : '')])
            ->all();
    }

    /**
     * Methods for a position, plus any Amrod inclusive offers ("Inclusive of 1 colour, 1 position pad printing")
     * that apply to this product, position and item colour. Inclusive keys look like "INC:<id>:<code>".
     */
    public static function methodOptions(?string $productId, ?string $positionCode, ?string $variantId, ?string $supplierId = null): array
    {
        $position = static::position($productId, $positionCode);
        if (! $position) {
            return [];
        }
        $colour = $variantId ? ProductVariant::whereKey($variantId)->value('colour_code') : null;
        $methods = $position->methods->filter(fn (BrandingMethod $m) => $m->allowsColour($colour));

        $options = [];
        foreach (static::inclusiveOffers($supplierId, $productId, $positionCode, $colour, $methods->pluck('branding_code')->all()) as $offer) {
            $options['INC:'.$offer->external_id.':'.$offer->branding_code] = '★ '.$offer->label()
                .($offer->min_quantity > 1 ? " - from {$offer->min_quantity} units" : '');
        }
        foreach ($methods as $m) {
            $options[$m->branding_code] = $m->name
                .($m->max_colours > 0 ? " (max {$m->max_colours} col)" : '')
                .(is_string(data_get($m->raw, 'numberOfColours')) && ! is_numeric(data_get($m->raw, 'numberOfColours')) ? ' · '.data_get($m->raw, 'numberOfColours') : '')
                .($m->max_print_size ? " · {$m->max_print_size}" : '');
        }

        return $options;
    }

    /** @return \Illuminate\Support\Collection<int, InclusiveBranding> one offer per inclusive id + code */
    public static function inclusiveOffers(?string $supplierId, ?string $productId, ?string $positionCode, ?string $colour, array $codes)
    {
        if (! $supplierId || ! $productId || ! $codes) {
            return collect();
        }
        $simpleCode = once(fn () => Product::whereKey($productId)->value('simple_code'));

        return InclusiveBranding::query()
            ->where('supplier_id', $supplierId)
            ->where('product_code', $simpleCode)
            ->whereIn('branding_code', $codes)
            ->get()
            ->filter(fn (InclusiveBranding $i) => $i->appliesTo($positionCode, $colour))
            ->unique(fn (InclusiveBranding $i) => $i->external_id.'|'.$i->branding_code)
            ->values();
    }

    public static function inclusiveBelowMinimum(string $value, mixed $quantity): bool
    {
        if (! str_starts_with($value, 'INC:')) {
            return false;
        }
        [, $id, $code] = explode(':', $value, 3);
        $min = once(fn () => (int) InclusiveBranding::where('external_id', $id)->where('branding_code', $code)->min('min_quantity'));

        return (int) $quantity < max(1, $min);
    }

    /** Fill the branding row from the chosen method or inclusive offer. */
    public static function applyMethodChoice(?string $choice, Set $set, Get $get): void
    {
        $productId = $get('../../product_id');
        $position = static::position($productId, $get('position_code'));

        if ($choice && str_starts_with($choice, 'INC:')) {
            [, $id, $code] = explode(':', $choice, 3);
            $offer = InclusiveBranding::where('external_id', $id)->where('branding_code', $code)->first();
            $method = $position?->methods->firstWhere('branding_code', $code);
            $set('branding_code', $code);
            $set('inclusive', true);
            $set('inclusive_id', $id);
            $set('replacement_code', $offer?->replacement_code);
            $set('colours', $offer?->number_of_colours);
            $set('method_inclusive', false);
            $set('method_name', trim(($method?->name ?? $code).' - '.($offer?->description ? trim($offer->description) : 'inclusive')));

            return;
        }

        $method = $choice ? $position?->methods->firstWhere('branding_code', $choice) : null;
        $set('branding_code', $choice);
        $set('inclusive', false);
        $set('inclusive_id', null);
        $set('replacement_code', null);
        $set('colours', null);
        $set('method_inclusive', (bool) data_get($method?->raw, 'brandingInclusiveMethod', false));
        $set('method_name', $method?->name);
    }

    public static function colourOptions(?string $supplierId, ?string $brandingCode): array
    {
        if (! $supplierId || ! $brandingCode) {
            return [];
        }

        return collect(app(BrandingPriceLookup::class)->colourOptions((int) $supplierId, $brandingCode))
            ->mapWithKeys(fn ($c) => [$c => $c.' colour'.($c > 1 ? 's' : '')])->all();
    }

    public static function preview(Get $get, Quote|SalesOrder $owner, bool $setupSeparately): HtmlString
    {
        $line = [
            'quantity' => $get('quantity'),
            'unit_cost' => $get('unit_cost'),
            'extra_cost' => $get('extra_cost'),
            'gp_percent' => $get('gp_percent'),
            'commission_percent' => $get('commission_percent'),
            'unit_price_override' => $get('unit_price_override'),
            'brandings' => array_values($get('brandings') ?? []),
        ];
        $p = app(LinePricer::class)->price($line, $get('supplier_id') ? (int) $get('supplier_id') : null, $setupSeparately);
        $rate = $owner->company?->vatRate() ?? 0.15;
        $incl = Vat::inclusiveLineTotal($p['unit_price'], $p['quantity'], $rate) + Vat::inclusiveLineTotal($p['setup_price'], 1, $rate);
        $r = fn ($v) => 'R '.number_format((float) $v, 2, '.', ' ');

        $missing = collect($p['brandings'])->filter(fn ($b) => ! $b['price_found'] && empty($b['inclusive']) && empty($b['method_inclusive']) && ! isset($b['unit_cost_override']))->pluck('branding_code')->filter();
        $inclusiveCount = collect($p['brandings'])->where('inclusive', true)->count();
        $warning = $inclusiveCount > 1
            ? '<div style="color:#b45309;margin-top:6px">Inclusive offers usually cover one position only - check with the supplier before quoting '.$inclusiveCount.' positions as included.</div>'
            : '';
        $warning .= $missing->isNotEmpty()
            ? '<div style="color:#b45309;margin-top:6px">No supplier branding price found for: '.e($missing->implode(', ')).'. Enter an override or check the branding price sync.</div>'
            : '';

        return new HtmlString(
            '<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;font-size:13px">'
            .'<div><div style="opacity:.6">Branding cost / unit</div><strong>'.$r($p['branding_unit_cost']).'</strong></div>'
            .'<div><div style="opacity:.6">Setup cost</div><strong>'.$r($p['setup_cost']).'</strong></div>'
            .'<div><div style="opacity:.6">Total cost</div><strong>'.$r($p['total_cost']).'</strong></div>'
            .'<div><div style="opacity:.6">GP</div><strong>'.$r($p['gp_amount']).' ('.number_format($p['gp_percent'], 1).'%)</strong></div>'
            .'<div><div style="opacity:.6">Sell / unit (ex VAT)</div><strong>'.$r($p['unit_price']).'</strong></div>'
            .'<div><div style="opacity:.6">Setup sell</div><strong>'.$r($p['setup_price']).'</strong></div>'
            .'<div><div style="opacity:.6">Line total ex VAT / incl VAT</div><strong>'.$r($p['line_total']).' / '.$r($incl).'</strong></div>'
            .'<div><div style="opacity:.6">Commission</div><strong>'.$r($p['commission_amount']).'</strong></div>'
            .'</div>'.$warning
        );
    }
}
