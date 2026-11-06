<?php

namespace App\Filament\Resources\Products;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
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
                    ->getStateUsing(fn (Product $record) => $record->primaryImage())->square()->imageSize(48),
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
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->columnSpanFull()->schema([
                Section::make()->columnSpan(1)->schema([
                    ImageEntry::make('images')->label('')->imageHeight(220)->limit(1),
                ]),
                Section::make()->columnSpan(2)->columns(2)->schema([
                    TextEntry::make('name')->columnSpanFull()->size('lg')->weight('bold'),
                    TextEntry::make('simple_code')->label('Code')->copyable(),
                    TextEntry::make('supplier.name'),
                    TextEntry::make('category.path')->label('Category'),
                    TextEntry::make('brand.name')->placeholder('-'),
                    TextEntry::make('branding_guide_url')->label('Branding guide')
                        ->url(fn ($state) => $state, shouldOpenInNewTab: true)->formatStateUsing(fn () => 'Open guide')->placeholder('-'),
                    TextEntry::make('synced_at')->since(),
                    TextEntry::make('description')->columnSpanFull()->html()->placeholder('-'),
                ]),
            ]),
            Section::make('Branding positions')->columnSpanFull()->schema([
                RepeatableEntry::make('brandingPositions')->label('')->columns(4)->schema([
                    TextEntry::make('name')->label('Position')
                        ->formatStateUsing(fn ($state, $record) => "{$record->code} - {$state}".($record->multiplier > 1 ? " (x{$record->multiplier})" : '')),
                    TextEntry::make('methods.name')->label('Methods')->badge()->columnSpan(2),
                    TextEntry::make('flags')->label('')
                        ->getStateUsing(fn ($record) => array_filter([$record->is_required ? 'Required' : null, $record->is_no_co_branding ? 'No co-branding' : null]))
                        ->badge()->color('warning'),
                ]),
            ]),
        ]);
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
