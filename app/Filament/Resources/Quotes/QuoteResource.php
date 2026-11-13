<?php

namespace App\Filament\Resources\Quotes;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Quotes\Pages\CreateQuote;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Quotes\Pages\ListQuotes;
use App\Filament\Resources\Quotes\RelationManagers\LinesRelationManager;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Filament\Support\Money;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Quote;
use App\Support\Access;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
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
                TextInput::make('number')->label('Quote number')->placeholder('Automatic')->maxLength(30)
                    ->visible(fn (): bool => Access::isAdmin())
                    ->required(fn (string $operation): bool => $operation === 'edit')
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', Filament::getTenant()?->id))
                    ->helperText('Leave blank for the next number. Change it to match your old system.')
                    ->columnSpanFull(),
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
                Select::make('contact_id')->label('Contact person')
                    ->options(fn (Get $get) => $get('customer_id') ? Contact::where('customer_id', $get('customer_id'))->orderByDesc('is_primary')->orderBy('name')->get()->mapWithKeys(fn (Contact $c) => [$c->id => $c->label()]) : [])
                    ->searchable()
                    ->placeholder(fn (Get $get) => $get('customer_id') ? 'Select or add a contact' : 'Pick the customer first')
                    ->createOptionForm([
                        TextInput::make('name')->required(),
                        TextInput::make('position'),
                        TextInput::make('email')->email(),
                        TextInput::make('phone')->tel(),
                    ])
                    ->createOptionUsing(fn (array $data, Get $get) => Contact::create($data + ['customer_id' => $get('customer_id'), 'is_primary' => Contact::where('customer_id', $get('customer_id'))->doesntExist()])->getKey())
                    ->disabled(fn (Get $get) => blank($get('customer_id')))
                    ->helperText('Printed on the quote and used as the email recipient'),
                Select::make('sales_rep_id')->label('Sales rep')
                    ->options(fn () => CustomerResource::salesRepOptions())
                    ->default(fn () => auth()->user()?->is_sales_rep ? auth()->id() : null),
                TextInput::make('reference')->placeholder('Event, campaign or customer ref'),
                DatePicker::make('quote_date')->default(today())->required(),
                DatePicker::make('valid_until')->default(fn () => today()->addDays(Filament::getTenant()?->quote_valid_days ?: 14)),
                DatePicker::make('required_by')->label('Customer needs it by'),
                Toggle::make('show_images')->label('Show product images on the PDF')->default(true),
                Radio::make('show_setup_separately')
                    ->label('How to show prices on the quote')
                    ->boolean(
                        trueLabel: 'Item price + setup charges shown separately',
                        falseLabel: 'All-in price per item (item, branding and setup combined, then divided by the quantity)',
                    )
                    ->default(true)
                    ->columnSpanFull()
                    ->helperText('Changing this re-prices every line. GP % stays the same either way.'),
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
            $field('cost_total', 'Total cost (ex VAT)'),
            $field('gp_amount', 'GP - what we make'),
            $field('gp_percent', 'GP %', '%'),
            $field('commission_total', 'Commission')->visible(fn ($record) => \App\Support\Access::canSeeCommission($record)),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('customer', 'salesRep'))
            ->columns([
                TextColumn::make('number')->label('Quote')->searchable()->sortable()->weight('bold'),
                TextColumn::make('customer.name')->label('Customer')->searchable()->sortable()->wrap()
                    ->description(fn (Quote $record) => $record->reference ? str($record->reference)->limit(40) : null),
                TextColumn::make('status')->badge(),
                TextColumn::make('quote_date')->label('Date')->date('j M')->sortable(),
                TextColumn::make('subtotal')->label('Sell ex VAT')->formatStateUsing(Money::formatter())->alignEnd()->sortable(),
                TextColumn::make('gp_amount')->label('GP')->formatStateUsing(Money::formatter())->alignEnd()->sortable()
                    ->description(fn (Quote $record) => number_format((float) $record->gp_percent, 1).'%')
                    ->color(fn (Quote $record) => $record->gp_percent < 20 ? 'danger' : ($record->gp_percent < 30 ? 'warning' : 'success')),
                TextColumn::make('salesRep.name')->label('Rep')->toggleable(),
                TextColumn::make('next_follow_up_at')->label('Follow up')->since()->placeholder('-')->toggleable(),
                // Off by default - switch on with the columns icon
                TextColumn::make('reference')->searchable()->limit(30)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cost_total')->label('Cost')->formatStateUsing(Money::formatter())->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')->label('Total incl VAT')->formatStateUsing(Money::formatter())->alignEnd()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(QuoteStatus::class)->multiple(),
                SelectFilter::make('sales_rep_id')->label('Rep')->options(fn () => CustomerResource::salesRepOptions()),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [LinesRelationManager::class, AttachmentsRelationManager::class];
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
