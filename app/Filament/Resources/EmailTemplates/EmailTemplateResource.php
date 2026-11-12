<?php

namespace App\Filament\Resources\EmailTemplates;

use App\Filament\Resources\EmailTemplates\Pages\CreateEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\EditEmailTemplate;
use App\Filament\Resources\EmailTemplates\Pages\ListEmailTemplates;
use App\Models\EmailTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';


    /** Admins only. */
    public static function canViewAny(): bool
    {
        return \App\Support\Access::isAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                Select::make('key')->label('Used for')->options(EmailTemplate::KEYS)->required()
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('company_id', \Filament\Facades\Filament::getTenant()?->id)),
                TextInput::make('name')->required(),
                TextInput::make('subject')->required()->columnSpanFull(),
                Textarea::make('body')->required()->rows(12)->columnSpanFull(),
                Toggle::make('is_active')->default(true),
                Text::make('Placeholders: {{ customer.name }}, {{ contact.name }}, {{ document.number }}, {{ document.total }}, '
                    .'{{ quote.valid_until }}, {{ order.deposit }}, {{ order.deposit_outstanding }}, {{ supplier.name }}, '
                    .'{{ supplier.contact }}, {{ rep.name }}, {{ user.name }}, {{ company.name }}, {{ company.phone }}')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('medium'),
                TextColumn::make('key')->label('Used for')->formatStateUsing(fn ($state) => EmailTemplate::KEYS[$state] ?? $state),
                TextColumn::make('subject')->limit(50),
                IconColumn::make('is_active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailTemplates::route('/'),
            'create' => CreateEmailTemplate::route('/create'),
            'edit' => EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}
