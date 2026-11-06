<?php

namespace App\Filament\Resources\Quotes;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\RelationManagers\LinesRelationManager;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Quote;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'reference', 'customer.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make('Quote')->columnSpan(2)->columns(2)->schema([
                Select::make('customer_id')->label('Customer')
                    ->relationship('customer', 'name', modifyQueryUsing: fn ($query) => $query->where('company_id', Filament::getTenant()?->id))
                    ->searchable()->preload()->required()->live()
                    ->createOptionForm(fn (Schema $schema) => CustomerResource::form($schema))
                    ->createOptionUsing(fn (array $data) => Customer::create($data + ['company_id' => Filament::getTenant()->id])->getKey())
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        $customer = $state ? Customer::with('contacts')->find($state) : null;
                        $set('contact_id', $customer?->primaryContact()?->id);
                        if ($customer?->sales_rep_id) {
                            $set('sales_rep_id', $customer->sales_rep_id);
                        }
                    }),
                Select::make('contact_id')->label('Contact')
                    ->options(fn (Get $get) => $get('customer_id') ? Contact::where('customer_id', $get('customer_id'))->pluck('name', 'id') : [])
                    ->searchable(),
                Select::make('sales_rep_id')->label('Sales rep')
                    ->options(fn () => CustomerResource::salesRepOptions())
                    ->default(fn () => auth()->id()),
                TextInput::make('reference')->placeholder('Event, campaign or customer ref'),
                DatePicker::make('quote_date')->default(today())->required(),
                DatePicker::make('valid_until')->default(fn () => today()->addDays(Filament::getTenant()?->quote_valid_days ?: 14)),
                DatePicker::make('required_by')->label('Customer needs it by'),
                Toggle::make('show_setup_separately')->label('Show setup costs as separate amounts')->default(true)
                    ->helperText('Off = setups spread into the unit price. Re-prices all lines.'),
                Textarea::make('notes')->label('Notes printed on the quote')->rows(3)->columnSpanFull(),
                Textarea::make('internal_notes')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Totals')->columnSpan(1)->schema([
                Select::make('status')->options(QuoteStatus::class)->default(QuoteStatus::Draft)->disabled()->dehydrated(false),
                Grid::make(1)->schema(static::totalFields()),
            ])->visibleOn('edit'),
        ]);
    }

    /** Read-only totals, refreshed by the edit page when lines change. */
    public static function totalFields(): array
    {
        $field = fn (string $name, string $label, string $prefix = 'R') => TextInput::make($name)->label($label)
            ->prefix($prefix ?: null)->disabled()->dehydrated(false)
            ->formatStateUsing(fn ($state) => number_format((float) $state, 2, '.', ' '));

        return [
            $field('subtotal', 'Subtotal (ex VAT)'),
            $field('vat_total', 'VAT'),
            $field('total', 'Total (incl VAT)'),
            $field('cost_total', 'Total cost'),
            $field('gp_amount', 'GP'),
            $field('gp_percent', 'GP %', '%'),
            $field('commission_total', 'Commission'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer', 'salesRep'))
            ->columns([
                TextColumn::make('number')->searchable()->sortable()->weight('bold'),
                TextColumn::make('customer.name')->searchable()->sortable()->wrap(),
                TextColumn::make('reference')->searchable()->limit(30)->toggleable(),
                TextColumn::make('salesRep.name')->label('Rep')->toggleable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('quote_date')->date('j M Y')->sortable(),
                TextColumn::make('total')->money('ZAR')->sortable(),
                TextColumn::make('gp_percent')->label('GP %')->suffix('%')->sortable(),
                TextColumn::make('next_follow_up_at')->label('Follow up')->since()->placeholder('-')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(QuoteStatus::class)->multiple(),
                SelectFilter::make('sales_rep_id')->label('Rep')->options(fn () => CustomerResource::salesRepOptions()),
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
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
