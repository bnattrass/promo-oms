<?php

namespace App\Filament\Pages;

use App\Enums\CommissionStatus;
use App\Enums\SalesOrderStatus;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Models\Activity;
use App\Models\SalesOrder;
use App\Services\Reports\CommissionReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/** Commission per sales order: what's earned, what's unpaid, what's been paid. Full admins only. */
class Commission extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.commission';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 45;

    protected static ?string $title = 'Commission';

    /** Keep filters in the URL so the dashboard rep cards can link straight to a rep's commission. */
    #[Url(as: 'filters')]
    public ?array $tableFilters = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdminOf(Filament::getTenant()) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }
        $count = SalesOrder::withoutGlobalScopes()->where('company_id', Filament::getTenant()?->id)
            ->where('commission_total', '>', 0)->commission(CommissionStatus::Unpaid)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Invoiced orders with unpaid commission';
    }

    protected function baseQuery(): Builder
    {
        return SalesOrder::withoutGlobalScopes()
            ->with('customer:id,name', 'salesRep:id,name')
            ->where('company_id', Filament::getTenant()->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', SalesOrderStatus::Cancelled)
            ->where('commission_total', '>', 0);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->defaultSort('order_date', 'desc')
            ->recordUrl(fn (SalesOrder $record) => SalesOrderResource::getUrl('edit', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->label('Order')->searchable()->sortable()->weight('bold'),
                TextColumn::make('customer.name')->searchable()->wrap(),
                TextColumn::make('salesRep.name')->label('Rep')->sortable(),
                TextColumn::make('order_date')->date('j M Y')->sortable(),
                TextColumn::make('invoice_number')->label('Invoice')->placeholder('-')
                    ->description(fn (SalesOrder $r) => $r->invoice_date?->format('j M Y')),
                TextColumn::make('subtotal')->label('Sales ex VAT')->money('ZAR')->sortable()
                    ->summarize(Sum::make()->money('ZAR')->label('')),
                TextColumn::make('gp_amount')->label('GP')->money('ZAR')->sortable()
                    ->summarize(Sum::make()->money('ZAR')->label('')),
                TextColumn::make('commission_total')->label('Commission')->money('ZAR')->sortable()->weight('bold')
                    ->summarize(Sum::make()->money('ZAR')->label('')),
                TextColumn::make('commission_state')->label('Status')->badge()
                    ->getStateUsing(fn (SalesOrder $r) => $r->commissionStatus()),
                TextColumn::make('commission_paid_at')->label('Paid')->date('j M Y')->placeholder('-')
                    ->description(fn (SalesOrder $r) => $r->commission_paid_reference),
            ])
            ->filters([
                SelectFilter::make('commission')
                    ->label('Commission')
                    ->options(CommissionStatus::class)
                    ->default(CommissionStatus::Unpaid->value)
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->commission($data['value']) : $query),
                SelectFilter::make('rep')
                    ->label('Sales rep')
                    ->options(fn () => Filament::getTenant()->users()->orderBy('name')->pluck('users.name', 'users.id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->where('sales_rep_id', $data['value']) : $query),
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')->label('Orders from'),
                        DatePicker::make('until')->label('Orders until'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('order_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('order_date', '<=', $d))),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                Action::make('pay')->label('Mark paid')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (SalesOrder $r) => ! $r->commission_paid_at)
                    ->schema($this->paymentSchema())
                    ->fillForm(fn (SalesOrder $r) => ['paid_on' => today(), 'amount' => $r->commission_total])
                    ->action(fn (SalesOrder $r, array $data) => $this->markPaid(collect([$r]), $data)),
                Action::make('unpay')->label('Undo')->color('gray')
                    ->visible(fn (SalesOrder $r) => (bool) $r->commission_paid_at)
                    ->requiresConfirmation()
                    ->action(fn (SalesOrder $r) => $this->markUnpaid(collect([$r]))),
            ])
            ->toolbarActions([
                BulkAction::make('payBulk')->label('Mark commission paid')->icon(Heroicon::OutlinedBanknotes)->color('success')
                    ->schema([
                        DatePicker::make('paid_on')->label('Paid on')->default(today())->required(),
                        TextInput::make('reference')->label('Payment reference')->placeholder('e.g. Payroll Nov 2026'),
                        Toggle::make('include_not_due')->label('Also pay orders that are not invoiced yet'),
                    ])
                    ->action(fn (Collection $records, array $data) => $this->markPaid($records, $data))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('unpayBulk')->label('Mark unpaid')->color('gray')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => $this->markUnpaid($records))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    private function paymentSchema(): array
    {
        return [
            DatePicker::make('paid_on')->label('Paid on')->required(),
            TextInput::make('amount')->label('Amount paid')->prefix('R')->numeric()->required()
                ->helperText('Normally the full commission; change it for a part payment or adjustment'),
            TextInput::make('reference')->label('Payment reference')->placeholder('e.g. Payroll Nov 2026'),
        ];
    }

    private function markPaid(Collection $orders, array $data): void
    {
        $paid = 0;
        $skipped = 0;
        foreach ($orders as $order) {
            if ($order->commission_paid_at) {
                continue;
            }
            if ($order->commissionStatus() === CommissionStatus::NotDue && empty($data['include_not_due']) && ! isset($data['amount'])) {
                $skipped++;

                continue;
            }
            $amount = isset($data['amount']) && $orders->count() === 1 ? (float) $data['amount'] : (float) $order->commission_total;
            $order->update([
                'commission_paid_at' => $data['paid_on'],
                'commission_paid_amount' => $amount,
                'commission_paid_reference' => $data['reference'] ?? null,
                'commission_paid_by' => auth()->id(),
            ]);
            Activity::log($order, 'commission', 'Commission of R '.number_format($amount, 2).' marked paid'.(! empty($data['reference']) ? " ({$data['reference']})" : ''));
            $paid++;
        }

        Notification::make()->success()
            ->title("Commission marked paid on {$paid} order(s)")
            ->body($skipped ? "{$skipped} order(s) not invoiced yet were skipped." : null)
            ->send();
    }

    private function markUnpaid(Collection $orders): void
    {
        foreach ($orders as $order) {
            if (! $order->commission_paid_at) {
                continue;
            }
            $order->update(['commission_paid_at' => null, 'commission_paid_amount' => null, 'commission_paid_reference' => null, 'commission_paid_by' => null]);
            Activity::log($order, 'commission', 'Commission payment undone');
        }
        Notification::make()->success()->title('Marked unpaid')->send();
    }

    /** Totals per rep for the rows currently shown (respects filters and search). */
    public function repTotals(): Collection
    {
        return CommissionReport::totalsByRep($this->getFilteredTableQuery());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Export (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(function () {
                    $rows = $this->getFilteredTableQuery()->with('customer:id,name', 'salesRep:id,name')->orderBy('order_date')->get();

                    return response()->streamDownload(function () use ($rows) {
                        $out = fopen('php://output', 'w');
                        fputcsv($out, ['Order', 'Order date', 'Customer', 'Rep', 'Invoice', 'Invoice date', 'Sales ex VAT', 'GP', 'Commission', 'Status', 'Paid on', 'Amount paid', 'Reference']);
                        foreach ($rows as $o) {
                            fputcsv($out, [
                                $o->number, $o->order_date?->format('Y-m-d'), $o->customer?->name, $o->salesRep?->name,
                                $o->invoice_number, $o->invoice_date?->format('Y-m-d'),
                                round((float) $o->subtotal, 2), round((float) $o->gp_amount, 2), round((float) $o->commission_total, 2),
                                $o->commissionStatus()->getLabel(), $o->commission_paid_at?->format('Y-m-d'),
                                $o->commission_paid_amount !== null ? round((float) $o->commission_paid_amount, 2) : '', $o->commission_paid_reference,
                            ]);
                        }
                        fclose($out);
                    }, 'commission-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
                }),
        ];
    }
}
