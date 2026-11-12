<?php

namespace App\Filament\Resources\Team;

use App\Filament\Resources\Team\Pages\CreateTeamMember;
use App\Filament\Resources\Team\Pages\EditTeamMember;
use App\Filament\Resources\Team\Pages\ListTeam;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Sales people and staff for the current company. Admins only. */
class TeamResource extends Resource
{
    public const ROLES = [
        'admin' => 'Admin - everything, incl. costs for all reps, commission, reports and settings',
        'sales' => 'Sales - quoting and the order process; own figures and commission only',
    ];

    protected static ?string $model = User::class;

    protected static ?string $tenantOwnershipRelationshipName = 'companies';

    protected static ?string $tenantRelationshipName = 'users';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Team & sales people';

    protected static ?string $modelLabel = 'team member';

    protected static ?string $slug = 'team';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isAdminOf(Filament::getTenant()) ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Person')->columns(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
                TextInput::make('phone')->tel(),
                TextInput::make('password')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->minLength(8)
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the current password' : null),
                Select::make('role')->options(self::ROLES)->default('sales')->required()->dehydrated(false),
                Toggle::make('is_active')->label('Can log in')->default(true),
            ]),
            Section::make('Sales')->columns(2)->schema([
                Toggle::make('is_sales_rep')->label('This person is a sales rep')->live()->columnSpanFull()
                    ->helperText('Sales reps appear in the rep pickers on quotes and customers, in sales reports, performance cards and commission.'),
                TextInput::make('commission_percent')->label('Commission (% of GP)')->numeric()->suffix('%')->default(0)
                    ->visible(fn (Get $get) => (bool) $get('is_sales_rep'))
                    ->helperText('Default for this rep on new quote lines; can be changed per line'),
                TextInput::make('monthly_target')->label('Monthly sales target (ex VAT)')->numeric()->prefix('R')
                    ->visible(fn (Get $get) => (bool) $get('is_sales_rep')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('medium'),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('role')->badge()
                    ->getStateUsing(fn (User $record) => $record->roleIn(Filament::getTenant()))
                    ->formatStateUsing(fn (?string $state) => $state ? str(self::ROLES[$state] ?? $state)->before(' (')->toString() : '-'),
                IconColumn::make('is_sales_rep')->label('Sales rep')->boolean(),
                TextColumn::make('commission_percent')->label('Commission')->suffix('%'),
                TextColumn::make('monthly_target')->label('Target')->money('ZAR')->placeholder('-'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTeam::route('/'),
            'create' => CreateTeamMember::route('/create'),
            'edit' => EditTeamMember::route('/{record}/edit'),
        ];
    }
}
