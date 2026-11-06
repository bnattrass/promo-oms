<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Product;
use App\Services\Orders\ProductImages;
use App\Suppliers\Fields;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Supplier catalogue browser (read only - data comes from supplier syncs). */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'simple_code'];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('category', 'supplier')
                ->withMin('variants', 'cost_price')
                ->withSum('variants', 'stock_on_hand'))
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('thumbnail')->label('')
                    ->getStateUsing(fn (Product $record) => app(ProductImages::class)->urlFor(null, $record))->square()->imageSize(48),
                TextColumn::make('simple_code')->label('Code')->searchable()->sortable()->copyable(),
                TextColumn::make('name')->searchable()->sortable()->wrap()->weight('medium'),
                TextColumn::make('category.path')->label('Category')->limit(40)->toggleable(),
                TextColumn::make('supplier.code')->label('Supplier')->badge()->color('gray'),
                TextColumn::make('variants_min_cost_price')->label('Cost from')->money('ZAR')->sortable(),
                TextColumn::make('variants_sum_stock_on_hand')->label('Stock')->numeric()->sortable(),
            ])
            ->filters([
                SelectFilter::make('supplier')->relationship('supplier', 'name'),
                SelectFilter::make('category')->relationship('category', 'path')->searchable()->preload(),
                SelectFilter::make('brand')->relationship('brand', 'name')->searchable()->preload(),
                TernaryFilter::make('is_active')->label('Active')->default(true),
                TernaryFilter::make('in_stock')->label('In stock')
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('variants', fn ($v) => $v->where('stock_on_hand', '>', 0)),
                        false: fn (Builder $query) => $query->whereDoesntHave('variants', fn ($v) => $v->where('stock_on_hand', '>', 0)),
                    ),
            ])
            ->recordActions([
                Action::make('guide')->label('Guide')->icon(Heroicon::OutlinedDocumentText)->color('gray')
                    ->url(fn (Product $record) => $record->branding_guide_url ? str_replace(' ', '%20', $record->branding_guide_url) : null, shouldOpenInNewTab: true)
                    ->visible(fn (Product $record) => filled($record->branding_guide_url)),
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make()->columnSpan(1)->schema([
                    TextEntry::make('main_image')->hiddenLabel()->html()
                        ->state(fn (Product $record) => static::mainImageHtml($record)),
                ]),
                Section::make()->columnSpan(2)->columns(2)->schema([
                    TextEntry::make('name')->columnSpanFull()->size('lg')->weight('bold'),
                    TextEntry::make('simple_code')->label('Code')->copyable(),
                    TextEntry::make('supplier.name'),
                    TextEntry::make('category.path')->label('Category'),
                    TextEntry::make('brand.name')->placeholder('-'),
                    TextEntry::make('guide')->label('Branding guide')->html()
                        ->state(fn (Product $record) => static::guideLinksHtml($record))->columnSpanFull(),
                    TextEntry::make('price_range')->label('Cost (ex VAT)')
                        ->state(fn (Product $record) => static::priceRange($record)),
                    TextEntry::make('stock_total')->label('Stock (all variants)')
                        ->state(fn (Product $record) => number_format($record->totalStock())),
                    TextEntry::make('description')->columnSpanFull()->html()->placeholder('-'),
                ]),
            ]),
            Section::make('Images')->columnSpanFull()->collapsible()->schema([
                TextEntry::make('gallery')->hiddenLabel()->html()
                    ->state(fn (Product $record) => static::galleryHtml($record)),
            ]),
            Section::make('Branding positions')->columnSpanFull()->schema([
                RepeatableEntry::make('brandingPositions')->label('')->columns(4)->schema([
                    TextEntry::make('name')->label('Position')
                        ->formatStateUsing(fn ($state, $record) => "{$record->code} - {$state}".($record->multiplier > 1 ? " (x{$record->multiplier})" : '')),
                    TextEntry::make('methods.name')->label('Methods')->badge(),
                    TextEntry::make('template')->label('Artwork template')->html()
                        ->state(fn ($record) => static::templateLinkHtml($record->product, $record->code))->placeholder('-'),
                    TextEntry::make('flags')->label('')
                        ->getStateUsing(fn ($record) => array_filter([$record->is_required ? 'Required' : null, $record->is_no_co_branding ? 'No co-branding' : null]))
                        ->badge()->color('warning'),
                ]),
            ]),
            Section::make('Specifications')->columnSpanFull()->collapsible()->columns(3)
                ->visible(fn (Product $record) => filled($record->specs))
                ->schema([
                    TextEntry::make('specs.material')->label('Material')->placeholder('-'),
                    TextEntry::make('specs.fit')->label('Fit')->placeholder('-'),
                    TextEntry::make('specs.feature')->label('Features')->placeholder('-'),
                    TextEntry::make('specs.minimum_order')->label('Minimum order')->placeholder('-'),
                    TextEntry::make('specs.order_increment')->label('Order in multiples of')->placeholder('-'),
                    TextEntry::make('specs.country_of_origin')->label('Country of origin')->placeholder('-'),
                    TextEntry::make('dimensions')->label('Product size')
                        ->state(fn (Product $record) => static::dimensions(data_get($record->specs, 'dimensions')))->placeholder('-'),
                    TextEntry::make('carton')->label('Carton')
                        ->state(fn (Product $record) => static::carton(data_get($record->specs, 'carton')))->placeholder('-'),
                    TextEntry::make('specs.made_to_order')->label('Made to order')->placeholder('-'),
                ]),
        ]);
    }

    // ---------------------------------------------------------------- display helpers

    private static function url(?string $url): string
    {
        return str_replace(' ', '%20', (string) $url);
    }

    private static function linkHtml(string $url, string $label): string
    {
        return '<a href="'.e(static::url($url)).'" target="_blank" rel="noopener" style="color:rgb(var(--primary-600));text-decoration:underline">'.e($label).' &#8599;</a>';
    }

    public static function mainImageHtml(Product $record): HtmlString
    {
        $url = app(ProductImages::class)->urlFor(null, $record);

        return new HtmlString($url
            ? '<a href="'.e(static::url($url)).'" target="_blank" rel="noopener"><img src="'.e(static::url($url)).'" alt="" style="width:100%;max-height:320px;object-fit:contain;border-radius:8px;background:#fff"></a>'
            : '<div style="opacity:.6">No image</div>');
    }

    public static function galleryHtml(Product $record): HtmlString
    {
        $urls = collect($record->images)->unique()->values();
        if ($urls->isEmpty()) {
            return new HtmlString('<div style="opacity:.6">No images from the supplier.</div>');
        }

        $tiles = $urls->map(fn ($u) => '<a href="'.e(static::url($u)).'" target="_blank" rel="noopener" title="Open full size" '
            .'style="display:block;border:1px solid rgba(127,127,127,.2);border-radius:8px;padding:6px;background:#fff">'
            .'<img src="'.e(static::url($u)).'" alt="" loading="lazy" style="width:100%;height:140px;object-fit:contain"></a>')->implode('');

        return new HtmlString('<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px">'.$tiles.'</div>'
            .'<div style="font-size:12px;opacity:.6;margin-top:8px">'.$urls->count().' image(s). Click to open full size and save.</div>');
    }

    public static function guideLinksHtml(Product $record): HtmlString
    {
        $links = [];
        if ($record->branding_guide_url) {
            $links[] = static::linkHtml($record->branding_guide_url, 'Full branding guide (PDF)');
        }
        foreach (static::templates($record) as $t) {
            if (! empty($t['url'])) {
                $links[] = static::linkHtml($t['url'], 'Template '.($t['position'] ?? '').(! empty($t['name']) ? ' - '.$t['name'] : ''));
            }
        }

        return new HtmlString($links ? implode('<br>', $links) : '<span style="opacity:.6">None supplied</span>');
    }

    public static function templateLinkHtml(?Product $product, ?string $positionCode): ?HtmlString
    {
        if (! $product || ! $positionCode) {
            return null;
        }
        $t = collect(static::templates($product))->first(fn ($t) => strcasecmp((string) ($t['position'] ?? ''), $positionCode) === 0);

        return $t && ! empty($t['url']) ? new HtmlString(static::linkHtml($t['url'], 'Open template')) : null;
    }

    private static function templates(Product $product): array
    {
        return array_values(array_filter(Fields::list($product->raw, 'brandingTemplates'), 'is_array'));
    }

    private static function priceRange(Product $record): string
    {
        $prices = $record->variants->whereNotNull('cost_price')->pluck('cost_price')->map(fn ($p) => (float) $p);
        if ($prices->isEmpty()) {
            return 'No price yet';
        }
        $r = fn ($v) => 'R '.number_format($v, 2, '.', ' ');

        return $prices->min() === $prices->max() ? $r($prices->min()) : $r($prices->min()).' - '.$r($prices->max());
    }

    private static function dimensions(mixed $d): ?string
    {
        if (! is_array($d)) {
            return null;
        }
        $parts = array_filter([
            Fields::float($d, 'length') ? Fields::float($d, 'length').' L' : null,
            Fields::float($d, 'width') ? Fields::float($d, 'width').' W' : null,
            Fields::float($d, 'height') ? Fields::float($d, 'height').' H' : null,
        ]);
        $weight = Fields::float($d, 'weight');

        return trim(implode(' x ', $parts).($weight ? " · {$weight} kg" : '')) ?: null;
    }

    private static function carton(mixed $c): ?string
    {
        if (! is_array($c)) {
            return null;
        }
        $size = array_filter([Fields::float($c, 'cartonSizeDimensionL'), Fields::float($c, 'cartonSizeDimensionW'), Fields::float($c, 'cartonSizeDimensionH')]);
        $pcs = Fields::int($c, 'piecesPerCarton');
        $kg = Fields::float($c, 'cartonWeight');

        return trim(($size ? implode(' x ', $size) : '').($pcs ? " · {$pcs} per carton" : '').($kg ? " · {$kg} kg" : ''), ' ·') ?: null;
    }

    public static function getRelations(): array
    {
        return [VariantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'view' => ViewProduct::route('/{record}'),
        ];
    }
}
