<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Enums\QuoteStatus;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\SalesOrders\SalesOrderResource;
use App\Filament\Support\DocumentActions;
use App\Models\Activity;
use App\Models\Quote;
use App\Services\Orders\QuoteConverter;
use App\Services\Pricing\DocumentTotals;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;
use Throwable;

/** @property Quote $record */
class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    #[On('document-totals-updated')]
    public function refreshTotals(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['subtotal', 'vat_total', 'total', 'cost_total', 'gp_amount', 'gp_percent', 'commission_total', 'status']);
    }

    /** Re-price lines when the setup display mode changes. */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged('show_setup_separately')) {
            app(DocumentTotals::class)->recalculate($this->record);
            $this->refreshTotals();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DocumentActions::email('quote_sent', 'Email quote')->after(fn () => $this->refreshTotals()),
            DocumentActions::pdf(),
            DocumentActions::costing(),

            Action::make('convert')
                ->label('Convert to sales order')
                ->icon(Heroicon::OutlinedArrowRightCircle)
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [QuoteStatus::Draft, QuoteStatus::Sent, QuoteStatus::Accepted], true))
                ->schema([
                    TextInput::make('customer_po_number')->label("Customer's order number"),
                    FileUpload::make('customer_po_file')
                        ->label("Customer's purchase order (optional)")
                        ->helperText('PDF, image or Word document, up to 20 MB. You can also upload it later on the sales order.')
                        ->disk('local')
                        ->directory('attachments/customer-po')
                        ->visibility('private')
                        ->acceptedFileTypes(['application/pdf', 'image/*', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-outlook', 'message/rfc822'])
                        ->maxSize(20480)
                        ->storeFileNamesIn('customer_po_file_name'),
                ])
                ->requiresConfirmation()
                ->modalDescription('Copies all lines to a new sales order. The quote is then locked.')
                ->action(function (array $data) {
                    try {
                        $order = app(QuoteConverter::class)->convert(
                            $this->record,
                            $data['customer_po_number'] ?? null,
                            $data['customer_po_file'] ?? null,
                            $data['customer_po_file_name'] ?? null,
                        );
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Could not convert')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title("Sales order {$order->number} created")->send();
                    $this->redirect(SalesOrderResource::getUrl('edit', ['record' => $order]));
                }),

            ActionGroup::make([
                Action::make('applyGp')
                    ->label('Set GP % on all lines')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->visible(fn () => $this->record->status->isEditable())
                    ->schema([TextInput::make('gp_percent')->label('GP %')->numeric()->suffix('%')->required()->default(fn () => $this->record->company->default_gp_percent)])
                    ->action(function (array $data) {
                        app(DocumentTotals::class)->applyGpToAllLines($this->record, (float) $data['gp_percent']);
                        $this->refreshTotals();
                        $this->dispatch('refresh-lines');
                        Notification::make()->success()->title('All lines re-priced')->send();
                    }),
                Action::make('reprice')
                    ->label('Re-price with latest supplier costs')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn () => $this->record->status->isEditable())
                    ->requiresConfirmation()
                    ->modalDescription('Updates each catalogue line to the current supplier cost and branding prices, keeping the GP %.')
                    ->action(function () {
                        foreach ($this->record->lines()->with('variant')->get() as $line) {
                            if ($line->variant?->cost_price !== null) {
                                $line->update(['unit_cost' => $line->variant->cost_price]);
                            }
                        }
                        app(DocumentTotals::class)->recalculate($this->record->refresh());
                        $this->refreshTotals();
                        Notification::make()->success()->title('Quote re-priced')->send();
                    }),
                Action::make('accepted')->label('Mark accepted')->icon(Heroicon::OutlinedCheckCircle)
                    ->visible(fn () => $this->record->status === QuoteStatus::Sent)
                    ->action(fn () => $this->setStatus(QuoteStatus::Accepted)),
                Action::make('declined')->label('Mark declined')->color('danger')
                    ->visible(fn () => in_array($this->record->status, [QuoteStatus::Draft, QuoteStatus::Sent], true))
                    ->requiresConfirmation()
                    ->action(fn () => $this->setStatus(QuoteStatus::Declined)),
                Action::make('reopen')->label('Reopen as draft')
                    ->visible(fn () => in_array($this->record->status, [QuoteStatus::Declined, QuoteStatus::Expired], true))
                    ->action(fn () => $this->setStatus(QuoteStatus::Draft)),
                DeleteAction::make(),
            ]),
        ];
    }

    private function setStatus(QuoteStatus $status): void
    {
        $this->record->update([
            'status' => $status,
            'accepted_at' => $status === QuoteStatus::Accepted ? now() : $this->record->accepted_at,
            'next_follow_up_at' => in_array($status, [QuoteStatus::Sent], true) ? $this->record->next_follow_up_at : null,
        ]);
        Activity::log($this->record, 'status_change', 'Status changed to '.$status->getLabel());
        $this->refreshTotals();
        Notification::make()->success()->title('Quote '.$status->getLabel())->send();
    }
}
