<?php

namespace App\Filament\Resources\SalesOrders;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\SalesOrders\Pages\EditSalesOrder;
use App\Filament\Resources\SalesOrders\Pages\ListSalesOrders;
use App\Filament\Resources\SalesOrders\RelationManagers\LinesRelationManager;
use App\Filament\Resources\SalesOrders\RelationManagers\PurchaseOrdersRelationManager;
use App\Models\Contact;
use App\Models\SalesOrder;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class SalesOrderResource extends Resource
{
    protected static ?string $model = SalesOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'reference', 'customer_po_number', 'customer.name'];
    }

    public static function canCreate(): bool
    {
        return false; // created from quotes
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', SalesOrderStatus::AwaitingDeposit)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Awaiting deposit';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Order')->columnSpan(2)->columns(2)->schema([
                Select::make('customer_id')->relationship('customer', 'name')->disabled()->dehydrated(false),
                Select::make('contact_id')->label('Contact')
                    ->options(fn (Get $get) => $get('customer_id') ? Contact::where('customer_id', $get('customer_id'))->pluck('name', 'id') : []),
                Select::make('sales_rep_id')->label('Sales rep')->options(fn () => CustomerResource::salesRepOptions()),
                TextInput::make('customer_po_number')->label("Customer's order no."),
                TextInput::make('reference'),
                DatePicker::make('order_date')->required(),
                DatePicker::make('required_by'),
                Text::make(fn (?SalesOrder $record) => $record?->quote
                    ? new HtmlString('From quote <a class="underline" href="'.QuoteResource::getUrl('edit', ['record' => $record->quote]).'">'.e($record->quote->number).'</a>')
                    : null),
                Textarea::make('delivery_address')->rows(3)->columnSpanFull(),
                Textarea::make('notes')->label('Notes printed on documents')->rows(2)->columnSpanFull(),
                Textarea::make('internal_notes')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Status and totals')->columnSpan(1)->schema([
                Select::make('status')->options(SalesOrderStatus::class)->required()
                    ->helperText('Moves automatically as deposits, POs and dispatch are recorded'),
                ...QuoteResource::totalFields(),
                TextInput::make('deposit_required')->prefix('R')->numeric(),
                TextInput::make('deposit_received')->prefix('R')->disabled()->dehydrated(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer', 'salesRep'))
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('bold'),
                TextColumn::make('customer.name')->searchable()->sortable()->wrap(),
                TextColumn::make('customer_po_number')->label('Cust. PO')->searchable()->toggleable(),
                TextColumn::make('reference')->limit(30)->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('order_date')->date('j M Y')->sortable(),
                TextColumn::make('required_by')->date('j M Y')->sortable()
                    ->color(fn ($state, SalesOrder $record) => $state && $state->isPast() && $record->status->isOpen() ? 'danger' : null),
                TextColumn::make('total')->money('ZAR')->sortable(),
                TextColumn::make('gp_percent')->label('GP %')->suffix('%')->toggleable(),
                TextColumn::make('salesRep.name')->label('Rep')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(SalesOrderStatus::class)->multiple(),
                SelectFilter::make('sales_rep_id')->label('Rep')->options(fn () => CustomerResource::salesRepOptions()),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class, PurchaseOrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesOrders::route('/'),
            'edit' => EditSalesOrder::route('/{record}/edit'),
        ];
    }
}
