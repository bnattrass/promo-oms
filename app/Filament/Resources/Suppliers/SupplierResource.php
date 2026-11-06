<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\RelationManagers\SyncRunsRelationManager;
use App\Jobs\SyncSupplier;
use App\Models\Supplier;
use App\Suppliers\SupplierRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

/** Suppliers and their API connections. Shared by all companies. */
class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $credentialSections = [];
        foreach (SupplierRegistry::DRIVERS as $driver => $class) {
            $fields = [];
            foreach ($class::credentialFields() as $key => $field) {
                $input = TextInput::make("credentials.{$key}")
                    ->label($field['label'])
                    ->validationAttribute($field['label'])
                    ->helperText($field['help'] ?? null)
                    // Stop the browser autofilling this app's own login into supplier fields:
                    // autofilled values are shown but never reach the form, so validation fails.
                    ->autocomplete('off')
                    ->extraInputAttributes(['data-1p-ignore' => 'true', 'data-lpignore' => 'true'])
                    ->required(fn (Get $get) => $get('driver') === $driver);
                if (! empty($field['password'])) {
                    $input->password()->revealable()->autocomplete('new-password');
                }
                $fields[] = $input;
            }

            $credentialSections[] = Section::make($class::label().' login')
                ->description('Stored encrypted. Use "Test connection" after saving.')
                ->columns(3)
                ->schema($fields)
                ->visible(fn (Get $get) => $get('driver') === $driver);
        }

        return $schema->components([
            Section::make('Supplier')->columns(2)->schema([
                TextInput::make('name')->required(),
                TextInput::make('code')->required()->maxLength(20)
                    ->unique(ignoreRecord: true)
                    ->dehydrateStateUsing(fn (?string $state) => strtoupper((string) $state))
                    ->helperText('Short code, e.g. AMROD'),
                Select::make('driver')
                    ->label('API connector')
                    ->options(SupplierRegistry::options())
                    ->placeholder('None (manual supplier)')
                    ->live(),
                Toggle::make('sync_enabled')
                    ->label('Sync automatically on schedule')
                    ->visible(fn (Get $get) => filled($get('driver'))),
                Toggle::make('is_active')->default(true),
            ]),
            ...$credentialSections,
            Section::make('Ordering and contact')->columns(2)->schema([
                TextInput::make('order_email')->email()->helperText('Purchase orders are emailed here'),
                TextInput::make('accounts_email')->email(),
                TextInput::make('contact_person'),
                TextInput::make('phone')->tel(),
                TextInput::make('website')->url(),
                Textarea::make('address')->rows(3),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('code')->badge(),
                TextColumn::make('driver')->label('API')
                    ->formatStateUsing(fn (?string $state) => $state ? (SupplierRegistry::options()[$state] ?? $state) : 'Manual')
                    ->placeholder('Manual'),
                IconColumn::make('sync_enabled')->label('Auto sync')->boolean(),
                TextColumn::make('products_count')->counts('products')->label('Products')->numeric(),
                TextColumn::make('last_stock_sync_at')->label('Stock synced')->since()->placeholder('Never'),
                TextColumn::make('last_full_sync_at')->label('Full sync')->since()->placeholder('Never'),
            ])
            ->recordActions([
                static::testConnectionAction(),
                static::syncNowAction(),
                EditAction::make(),
            ]);
    }

    public static function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->visible(fn (Supplier $record) => $record->hasApi())
            ->action(function (Supplier $record) {
                try {
                    $message = $record->connector()->testConnection();
                    Notification::make()->success()->title('Connected to '.$record->name)->body($message)->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Connection failed')->body($e->getMessage())->persistent()->send();
                }
            });
    }

    public static function syncNowAction(): Action
    {
        return Action::make('syncNow')
            ->label('Sync now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (Supplier $record) => $record->hasApi())
            ->schema([
                Select::make('mode')->options([
                    'full' => 'Full catalogue (first import / weekly resync, can take several minutes)',
                    'changes' => 'Changes from the last 24 hours',
                ])->default('full')->required(),
                Select::make('modules')->multiple()
                    ->options(fn (Supplier $record) => collect($record->connector()::modules())
                        ->mapWithKeys(fn ($m) => [$m => str($m)->replace('_', ' ')->title()->toString()])->all())
                    ->placeholder('All modules'),
            ])
            ->action(function (Supplier $record, array $data) {
                SyncSupplier::dispatch($record, $data['mode'], $data['modules'] ?: null);
                Notification::make()->success()->title('Sync queued')
                    ->body('Progress shows under Sync runs on the supplier page.')->send();
            });
    }

    public static function getRelations(): array
    {
        return [SyncRunsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
