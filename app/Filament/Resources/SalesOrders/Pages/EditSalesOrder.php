<?php

namespace App\Filament\Resources\SalesOrders\Pages;

use App\Enums\SalesOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Support\DocumentActions;
use App\Models\Activity;
use App\Models\SalesOrder;
use App\Services\Orders\PurchaseOrderGenerator;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;

/** @property SalesOrder $record */
class EditSalesOrder extends EditRecord
{
    protected static string $resource = SalesOrderResource::class;

    #[On('document-totals-updated')]
    public function refreshTotals(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['subtotal', 'vat_total', 'total', 'cost_total', 'gp_amount', 'gp_percent', 'commission_total', 'status', 'deposit_required', 'deposit_received']);
    }

    protected function afterSave(): void
    {
        if ($this->record->wasChanged('status')) {
            Activity::log($this->record, 'status_change', 'Status changed to '.$this->record->status->getLabel());
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deposit')
                ->label('Record deposit')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(fn () => $this->record->depositOutstanding() > 0)
                ->schema([
                    TextInput::make('amount')->prefix('R')->numeric()->required()->default(fn () => $this->record->depositOutstanding()),
                    DatePicker::make('received_on')->default(today())->required(),
                ])
                ->action(function (array $data) {
                    $received = round((float) $this->record->deposit_received + (float) $data['amount'], 2);
                    $this->record->update([
                        'deposit_received' => $received,
                        'deposit_received_at' => $data['received_on'],
                        'status' => $this->record->status === SalesOrderStatus::AwaitingDeposit && $received >= (float) $this->record->deposit_required
                            ? SalesOrderStatus::Artwork : $this->record->status,
                    ]);
                    Activity::log($this->record, 'payment', 'Deposit of R '.number_format((float) $data['amount'], 2).' received');
                    $this->refreshTotals();
                    Notification::make()->success()->title('Deposit recorded')->send();
                }),

            Action::make('generatePos')
                ->label('Create purchase orders')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->visible(fn () => $this->record->lines()->whereNotNull('supplier_id')->whereDoesntHave('purchaseOrderLines')->exists())
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->depositOutstanding() > 0
                    ? 'Warning: the deposit has not been received in full. One draft PO is created per supplier.'
                    : 'One draft PO is created per supplier for lines not yet ordered.')
                ->action(function () {
                    $pos = app(PurchaseOrderGenerator::class)->generate($this->record);
                    if (in_array($this->record->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Artwork, SalesOrderStatus::ArtworkApproved], true)) {
                        $this->record->update(['status' => SalesOrderStatus::Ordered]);
                    }
                    $this->refreshTotals();
                    $this->dispatch('refresh-lines');
                    Notification::make()->success()
                        ->title($pos->count().' purchase order(s) created: '.$pos->pluck('number')->implode(', '))
                        ->body('Review and email them from the Purchase orders tab.')
                        ->actions($pos->map(fn ($po) => Action::make('open'.$po->id)->label("Open {$po->number}")
                            ->url(PurchaseOrderResource::getUrl('edit', ['record' => $po])))->all())
                        ->send();
                }),

            DocumentActions::email('sales_order_confirmation', 'Email confirmation'),
            DocumentActions::pdf(),

            ActionGroup::make([
                DocumentActions::email('deposit_reminder', 'Send deposit reminder')
                    ->visible(fn () => $this->record->depositOutstanding() > 0),
                Action::make('cancel')->label('Cancel order')->color('danger')->icon(Heroicon::OutlinedXCircle)
                    ->visible(fn () => $this->record->status->isOpen())
                    ->requiresConfirmation()
                    ->action(function () {
                        $this->record->update(['status' => SalesOrderStatus::Cancelled]);
                        Activity::log($this->record, 'status_change', 'Order cancelled');
                        $this->refreshTotals();
                    }),
            ]),
        ];
    }
}
