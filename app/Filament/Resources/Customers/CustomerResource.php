<?php

namespace App\Filament\Resources\Customers;

use App\Enums\PaymentTerms;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'account_code', 'contacts.name', 'contacts.email'];
    }

    public static function salesRepOptions(): array
    {
        return Filament::getTenant()?->users()->where('users.is_sales_rep', true)->where('users.is_active', true)
            ->orderBy('name')->pluck('users.name', 'users.id')->all() ?? [];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')->columns(2)->schema([
                TextInput::make('name')->required()->columnSpanFull(),
                TextInput::make('account_code'),
                TextInput::make('vat_number')->label('VAT number'),
                TextInput::make('phone')->label('Main phone')->tel(),
                Select::make('sales_rep_id')->label('Sales rep')->options(fn () => static::salesRepOptions())->searchable(),
            ]),
            Section::make('Contact people')
                ->description('Add everyone you deal with at this company. The main contact is selected on new quotes automatically.')
                ->schema([
                    Repeater::make('contacts')
                        ->relationship()
                        ->hiddenLabel()
                        ->addActionLabel('Add contact person')
                        ->defaultItems(1)
                        ->columns(2)
                        ->collapsible()
                        ->schema([
                            TextInput::make('name')->required(),
                            TextInput::make('position')->placeholder('e.g. Marketing manager'),
                            TextInput::make('email')->email(),
                            TextInput::make('phone')->tel(),
                            Toggle::make('is_primary')->label('Main contact (selected on new quotes)')->columnSpanFull(),
                        ]),
                ]),
            Section::make('Terms and pricing')->columns(3)->schema([
                Select::make('payment_terms')->options(PaymentTerms::class)->default(PaymentTerms::Deposit)->required()->live(),
                TextInput::make('deposit_percent')->label('Deposit %')->numeric()->suffix('%')->default(50)
                    ->visible(fn (Get $get) => $get('payment_terms') !== PaymentTerms::ThirtyDays->value
                        && $get('payment_terms') !== PaymentTerms::ThirtyDays),
                TextInput::make('default_gp_percent')->label('Default GP %')->numeric()->suffix('%')
                    ->helperText('Leave blank to use the company default'),
            ]),
            Section::make('Addresses')->columns(2)->schema([
                Textarea::make('billing_address')->rows(4),
                Textarea::make('delivery_address')->rows(4),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->with('contacts', 'salesRep'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('account_code')->searchable()->toggleable(),
                TextColumn::make('main_contact')->label('Main contact')
                    ->getStateUsing(fn (Customer $record) => $record->primaryContact()?->name)->placeholder('-'),
                TextColumn::make('phone')->toggleable(),
                TextColumn::make('salesRep.name')->label('Rep'),
                TextColumn::make('payment_terms')->badge()->color('gray'),
                TextColumn::make('quotes_count')->counts('quotes')->label('Quotes'),
                TextColumn::make('sales_orders_count')->counts('salesOrders')->label('Orders'),
            ])
            ->filters([
                SelectFilter::make('sales_rep_id')->label('Rep')->options(fn () => static::salesRepOptions()),
                TrashedFilter::make(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
