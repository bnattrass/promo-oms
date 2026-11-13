<?php

namespace App\Filament\Support;

use App\Enums\LineType;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Pricing\DocumentTotals;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use App\Services\Orders\ProductImages;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Table + actions for priced lines, shared by quote and sales-order relation managers. */
class PricedLinesTable
{
    public static function configure(Table $table, RelationManager $rm, bool $editable): Table
    {
        /** @var Quote|SalesOrder $owner */
        $owner = $rm->getOwnerRecord();
        $after = function () use ($rm) {
            $owner = $rm->getOwnerRecord()->refresh();
            app(DocumentTotals::class)->recalculate($owner, reprice: false);
            $rm->dispatch('document-totals-updated');
        };
        $nextSort = fn () => ($owner->lines()->max('sort') ?? 0) + 1;
        $rand = fn ($state) => 'R '.number_format((float) $state, 2, '.', ' ');

        return $table
            ->reorderable($editable ? 'sort' : null)
            ->defaultSort('sort')
            ->modifyQueryUsing(fn ($query) => $query->with('variant.product', 'product'))
            ->columns([
                ImageColumn::make('thumbnail')->label('')->imageSize(36)
                    ->getStateUsing(fn (Model $record) => app(ProductImages::class)->urlFor($record->variant, $record->product)),
                TextColumn::make('description')->label('Item')->wrap()
                    ->formatStateUsing(fn ($state, Model $record) => trim(($record->item_code ? $record->item_code.' · ' : '').$state))
                    ->description(fn (Model $record) => static::lineSummary($record))
                    ->extraAttributes(['style' => 'min-width:14rem; max-width:22rem']),
                TextInputColumn::make('quantity')->label('Qty')->rules(['required', 'integer', 'min:1'])
                    ->inputMode('numeric')->width('6rem')
                    ->disabled(! $editable)->extraInputAttributes(['style' => 'text-align:right'])
                    ->updateStateUsing(function ($state, Model $record) use ($owner, $after) {
                        PricedLineForm::repriceRecord($record, ['quantity' => (int) $state], $owner);
                        $after();
                    }),
                TextColumn::make('total_cost')->label('Cost')->formatStateUsing($rand)->alignEnd(),
                TextInputColumn::make('gp_percent')->label('GP %')->rules(['required', 'numeric', 'min:0', 'max:95'])
                    ->inputMode('decimal')->width('5rem')
                    ->disabled(! $editable)->extraInputAttributes(['style' => 'text-align:right'])
                    ->tooltip('Type a new GP % and press Enter. Clears any fixed sell price on the line.')
                    ->updateStateUsing(function ($state, Model $record) use ($owner, $after) {
                        PricedLineForm::repriceRecord($record, ['gp_percent' => (float) $state, 'unit_price_override' => null], $owner);
                        $after();
                    }),
                TextInputColumn::make('unit_price')->label('Unit sell')->rules(['required', 'numeric', 'min:0'])
                    ->inputMode('decimal')->width('6.5rem')
                    ->disabled(! $editable)->extraInputAttributes(['style' => 'text-align:right'])
                    ->tooltip('Type a sell price to fix it; GP % is recalculated.')
                    ->updateStateUsing(function ($state, Model $record) use ($owner, $after) {
                        PricedLineForm::repriceRecord($record, ['unit_price_override' => (float) $state], $owner);
                        $after();
                    }),
                TextColumn::make('setup_price')->label('Setup sell')->toggleable()->alignEnd()
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? $rand($state) : '-'),
                TextColumn::make('line_total')->label('Line total')->formatStateUsing($rand)->alignEnd()->weight('bold'),
                TextColumn::make('gp_amount')->label('GP')->formatStateUsing($rand)->alignEnd()
                    ->color(fn (Model $record) => $record->gp_percent < 20 ? 'danger' : ($record->gp_percent < 30 ? 'warning' : 'success')),
                // Detail columns, off by default (switch on with the columns icon above the table)
                TextColumn::make('item_code')->label('Code')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('unit_cost')->label('Item cost /u')->formatStateUsing($rand)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('branding_unit_cost')->label('Brand cost /u')->formatStateUsing($rand)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('setup_cost')->label('Setup cost')->formatStateUsing($rand)->alignEnd()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('commission_amount')->label('Comm.')->formatStateUsing($rand)->alignEnd()->toggleable(isToggledHiddenByDefault: true)
                    ->visible(fn () => \App\Support\Access::canSeeCommission($owner)),
                ...($owner instanceof SalesOrder ? [TextColumn::make('stage')->badge()] : []),
            ])
            ->headerActions($editable ? [
                CreateAction::make()->label('Add catalogue item')->icon(Heroicon::OutlinedCube)->modalWidth('6xl')
                    ->mutateDataUsing(fn (array $data) => PricedLineForm::priceData($data + ['sort' => $nextSort(), 'line_type' => 'catalogue'], $owner))
                    ->after($after),
                CreateAction::make('custom')->label('Add custom line')->icon(Heroicon::OutlinedPlus)->color('gray')
                    ->modalHeading('Add custom line')->modalDescription('Courier, design work, setup, labour or anything not in a supplier catalogue.')
                    ->modalWidth('4xl')
                    ->schema(PricedLineForm::customComponents($owner))
                    ->mutateDataUsing(fn (array $data) => PricedLineForm::priceData($data + ['sort' => $nextSort(), 'brandings' => []], $owner))
                    ->after($after),
            ] : [])
            ->recordActions($editable ? [
                EditAction::make()->iconButton()->tooltip('Edit line')->modalWidth(fn (Model $record) => $record->isCatalogue() ? '6xl' : '4xl')
                    ->schema(fn (Model $record) => $record->isCatalogue() ? PricedLineForm::components($owner) : PricedLineForm::customComponents($owner))
                    ->mutateDataUsing(fn (array $data, Model $record) => PricedLineForm::priceData($data + ['line_type' => $record->line_type], $owner))
                    ->after($after),
                ReplicateAction::make()->label('Duplicate')->icon(Heroicon::OutlinedDocumentDuplicate)->iconButton()->tooltip('Duplicate line')
                    ->excludeAttributes(['id'])
                    ->schema([])
                    ->after($after),
                DeleteAction::make()->iconButton()->tooltip('Delete line')->after($after),
            ] : [
                Action::make('locked')->label('Locked')->disabled()->tooltip('Lines are locked once purchase orders exist'),
            ]);
    }

    /** Second line under each item: type or branding, plus where the cost comes from. */
    public static function lineSummary(Model $record): ?string
    {
        $r = fn ($v) => 'R'.number_format((float) $v, 2);

        $branding = collect($record->brandings)->map(fn ($b) => trim(
            ($b['position_code'] ?? $b['position_name'] ?? '').' '.($b['method_name'] ?? $b['branding_code'] ?? '')
            .(! empty($b['colours']) && $b['colours'] > 0 ? " {$b['colours']}col" : '')
            .(($b['placements'] ?? 1) > 1 ? " x{$b['placements']}" : '')
            .(empty($b['price_found']) && empty($b['inclusive']) && empty($b['method_inclusive']) && blank($b['unit_cost_override'] ?? null) ? ' - NO PRICE' : '')
        ))->filter()->implode(' · ');

        $type = $record->isCatalogue() ? null : LineType::tryFrom($record->line_type)?->getLabel();
        $costs = 'cost '.$r($record->unit_cost).'/u'
            .((float) $record->branding_unit_cost > 0 ? ' + brand '.$r($record->branding_unit_cost).'/u' : '')
            .((float) $record->setup_cost > 0 ? ' + setup '.$r($record->setup_cost) : '');

        return implode('  |  ', array_filter([$type, $branding ?: null, $costs]));
    }
}
