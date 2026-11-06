<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\RelationManagers\LinesRelationManager;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\PurchaseOrder;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canCreate(): bool
    {
        return false; // generated from sales orders
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', PurchaseOrderStatus::Draft)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Draft POs not yet sent';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Purchase order')->columnSpan(2)->columns(2)->schema([
                Select::make('supplier_id')->relationship('supplier', 'name')->disabled()->dehydrated(false),
                Text::make(fn (?PurchaseOrder $record) => $record?->salesOrder
                    ? new HtmlString('For sales order <a class="underline" href="'.SalesOrderResource::getUrl('edit', ['record' => $record->salesOrder]).'">'
                        .e($record->salesOrder->number).'</a> · '.e($record->salesOrder->customer?->name))
                    : null),
                DatePicker::make('order_date')->required(),
                DatePicker::make('expected_date')->label('Expected from supplier'),
                TextInput::make('supplier_reference')->label("Supplier's order / job no."),
                Textarea::make('notes')->label('Notes printed on the PO')->rows(3)->columnSpanFull(),
            ]),
            Section::make('Status and totals')->columnSpan(1)->schema([
                Select::make('status')->options(PurchaseOrderStatus::class)->required(),
                TextInput::make('subtotal')->prefix('R')->disabled()->dehydrated(false),
                TextInput::make('vat_total')->label('VAT')->prefix('R')->disabled()->dehydrated(false),
                TextInput::make('total')->prefix('R')->disabled()->dehydrated(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('supplier', 'salesOrder.customer'))
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('bold'),
                TextColumn::make('supplier.name')->sortable(),
                TextColumn::make('salesOrder.number')->label('Sales order')->searchable(),
                TextColumn::make('salesOrder.customer.name')->label('Customer')->wrap()->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('supplier_reference')->label('Supplier ref')->searchable()->toggleable(),
                TextColumn::make('order_date')->date('j M Y')->sortable(),
                TextColumn::make('expected_date')->date('j M Y')->sortable()
                    ->color(fn ($state, PurchaseOrder $record) => $state && $state->isPast()
                        && ! in_array($record->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Cancelled], true) ? 'danger' : null),
                TextColumn::make('total')->money('ZAR'),
            ])
            ->filters([
                SelectFilter::make('status')->options(PurchaseOrderStatus::class)->multiple(),
                SelectFilter::make('supplier')->relationship('supplier', 'name'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}
