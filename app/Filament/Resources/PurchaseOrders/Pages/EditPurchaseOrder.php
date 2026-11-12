<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\LineStage;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\TopSaveAction;
use App\Models\Activity;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/** @property PurchaseOrder $record */
class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TopSaveAction::make($this),
            DocumentActions::email('purchase_order', 'Email to supplier')
                ->after(fn () => $this->refreshFormData(['status'])),
            DocumentActions::pdf(),

            Action::make('receive')
                ->label('Receive goods')
                ->icon(Heroicon::OutlinedTruck)
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::Acknowledged, PurchaseOrderStatus::PartiallyReceived], true))
                ->fillForm(fn () => ['lines' => $this->record->lines->map(fn ($l) => [
                    'id' => $l->id,
                    'description' => "{$l->item_code} {$l->description}",
                    'ordered' => $l->quantity,
                    'already' => $l->quantity_received,
                    'receive' => max(0, $l->quantity - $l->quantity_received),
                ])->all()])
                ->schema([
                    Repeater::make('lines')->label('')->addable(false)->deletable(false)->reorderable(false)->columns(4)->schema([
                        TextInput::make('description')->disabled()->columnSpan(2),
                        TextInput::make('already')->label('Received so far')->disabled(),
                        TextInput::make('receive')->label('Receiving now')->numeric()->minValue(0)->required(),
                        Hidden::make('id'),
                    ]),
                ])
                ->modalWidth('4xl')
                ->action(function (array $data) {
                    DB::transaction(function () use ($data) {
                        foreach ($data['lines'] as $row) {
                            $line = $this->record->lines()->find($row['id']);
                            $qty = (int) $row['receive'];
                            if (! $line || $qty <= 0) {
                                continue;
                            }
                            $line->increment('quantity_received', $qty);
                            if ($soLine = $line->salesOrderLine) {
                                $soLine->increment('quantity_received', $qty);
                                if ($soLine->fresh()->quantity_received >= $soLine->quantity) {
                                    $soLine->update(['stage' => LineStage::Ready]);
                                }
                            }
                        }

                        $lines = $this->record->lines()->get();
                        $complete = $lines->every(fn ($l) => $l->quantity_received >= $l->quantity);
                        $this->record->update(['status' => $complete ? PurchaseOrderStatus::Received : PurchaseOrderStatus::PartiallyReceived]);

                        $order = $this->record->salesOrder;
                        if ($order && $order->lines()->whereColumn('quantity_received', '<', 'quantity')->doesntExist()) {
                            $order->update(['status' => SalesOrderStatus::Ready]);
                        }
                        Activity::log($this->record, 'received', $complete ? 'All goods received' : 'Partial delivery received (rest on back order)');
                    });
                    $this->refreshFormData(['status']);
                    Notification::make()->success()->title('Goods received')->send();
                }),

            Action::make('acknowledged')
                ->label('Supplier confirmed')
                ->color('gray')
                ->visible(fn () => $this->record->status === PurchaseOrderStatus::Sent)
                ->action(function () {
                    $this->record->update(['status' => PurchaseOrderStatus::Acknowledged]);
                    $this->record->salesOrder?->lines()->whereIn('id', $this->record->lines()->pluck('sales_order_line_id'))
                        ->update(['stage' => LineStage::InProduction->value]);
                    if ($this->record->salesOrder?->status === SalesOrderStatus::Ordered) {
                        $this->record->salesOrder->update(['status' => SalesOrderStatus::InProduction]);
                    }
                    $this->refreshFormData(['status']);
                }),

            DeleteAction::make()->visible(fn () => $this->record->status === PurchaseOrderStatus::Draft),
        ];
    }
}
