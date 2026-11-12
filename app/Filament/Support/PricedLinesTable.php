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

        return $table
            ->reorderable($editable ? 'sort' : null)
            ->defaultSort('sort')
            ->modifyQueryUsing(fn ($query) => $query->with('variant.product', 'product'))
            ->columns([
                ImageColumn::make('thumbnail')->label('')->imageSize(40)
                    ->getStateUsing(fn (Model $record) => app(ProductImages::class)->urlFor($record->variant, $record->product)),
                TextColumn::make('item_code')->label('Code')->placeholder('-')
                    ->description(fn (Model $record) => $record->isCatalogue() ? null : LineType::tryFrom($record->line_type)?->getLabel()),
                TextColumn::make('description')->wrap()
                    ->description(fn (Model $record) => collect($record->brandings)->map(fn ($b) => trim(
                        ($b['position_name'] ?? $b['position_code'] ?? '').': '.($b['method_name'] ?? $b['branding_code'] ?? '')
                        .(! empty($b['colours']) && $b['colours'] > 0 ? " {$b['colours']}col" : '')
                        .(($b['placements'] ?? 1) > 1 ? " x{$b['placements']}" : '')
                        .(! empty($b['inclusive']) || ! empty($b['method_inclusive']) ? ' (inclusive)' : '')
                        .(empty($b['price_found']) && empty($b['inclusive']) && empty($b['method_inclusive']) && empty($b['unit_cost_override']) ? ' - NO PRICE' : '')
                    ))->implode(' · ') ?: null),
                TextInputColumn::make('quantity')->label('Qty')->type('number')->rules(['required', 'integer', 'min:1'])
                    ->disabled(! $editable)->extraAttributes(['style' => 'width:6rem'])
                    ->updateStateUsing(function ($state, Model $record) use ($owner, $after) {
                        PricedLineForm::repriceRecord($record, ['quantity' => (int) $state], $owner);
                        $after();
                    }),
                TextColumn::make('unit_cost')->label('Item cost')->money('ZAR')->toggleable(),
                TextColumn::make('branding_unit_cost')->label('Brand cost')->money('ZAR')->toggleable(),
                TextColumn::make('setup_cost')->label('Setup cost')->money('ZAR')->toggleable(),
                TextColumn::make('total_cost')->label('Total cost')->money('ZAR'),
                TextInputColumn::make('gp_percent')->label('GP %')->type('number')->rules(['required', 'numeric', 'min:0', 'max:95'])
                    ->disabled(! $editable)->extraAttributes(['style' => 'width:6rem'])
                    ->tooltip('Type a new GP % and press Enter. Clears any fixed sell price on the line.')
                    ->updateStateUsing(function ($state, Model $record) use ($owner, $after) {
                        PricedLineForm::repriceRecord($record, ['gp_percent' => (float) $state, 'unit_price_override' => null], $owner);
                        $after();
                    }),
                TextInputColumn::make('unit_price')->label('Unit sell')->type('number')->rules(['required', 'numeric', 'min:0'])
                    ->disabled(! $editable)->extraAttributes(['style' => 'width:8rem'])
                    ->tooltip('Type a sell price to fix it; GP % is recalculated.')
                    ->updateStateUsing(function ($state, Model $record) use ($owner, $after) {
                        PricedLineForm::repriceRecord($record, ['unit_price_override' => (float) $state], $owner);
                        $after();
                    }),
                TextColumn::make('setup_price')->label('Setup sell')->money('ZAR')->toggleable(),
                TextColumn::make('line_total')->label('Line total')->money('ZAR')->weight('bold'),
                TextColumn::make('gp_amount')->label('GP')->money('ZAR')->toggleable(),
                TextColumn::make('commission_amount')->label('Comm.')->money('ZAR')->toggleable(isToggledHiddenByDefault: true)
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
                EditAction::make()->modalWidth(fn (Model $record) => $record->isCatalogue() ? '6xl' : '4xl')
                    ->schema(fn (Model $record) => $record->isCatalogue() ? PricedLineForm::components($owner) : PricedLineForm::customComponents($owner))
                    ->mutateDataUsing(fn (array $data, Model $record) => PricedLineForm::priceData($data + ['line_type' => $record->line_type], $owner))
                    ->after($after),
                ReplicateAction::make()->label('Duplicate')->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->excludeAttributes(['id'])
                    ->schema([])
                    ->after($after),
                DeleteAction::make()->after($after),
            ] : [
                Action::make('locked')->label('Locked')->disabled()->tooltip('Lines are locked once purchase orders exist'),
            ]);
    }
}
