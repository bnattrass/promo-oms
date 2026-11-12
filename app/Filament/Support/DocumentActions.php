<?php

namespace App\Filament\Support;

use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Services\Orders\DocumentMailer;
use App\Services\Orders\DocumentPdf;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/** PDF download and email actions for quotes, sales orders and purchase orders. */
class DocumentActions
{
    public static function pdf(): Action
    {
        return Action::make('pdf')
            ->label('PDF')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Quote|SalesOrder|PurchaseOrder $record) {
                $pdf = app(DocumentPdf::class);
                $content = $pdf->render($record);

                return response()->streamDownload(fn () => print ($content), $pdf->filename($record), ['Content-Type' => 'application/pdf']);
            });
    }

    /** Internal cost / sell / GP sheet. Never emailed to customers. */
    public static function costing(): Action
    {
        return Action::make('costing')
            ->label('Costing sheet')
            ->icon(Heroicon::OutlinedCalculator)
            ->color('gray')
            ->tooltip('Internal: cost, selling price and GP per line')
            ->action(function (Quote|SalesOrder $record) {
                $content = app(DocumentPdf::class)->renderCosting($record);

                return response()->streamDownload(fn () => print ($content), "{$record->number}-costing.pdf", ['Content-Type' => 'application/pdf']);
            });
    }

    public static function email(string $templateKey, string $label): Action
    {
        return Action::make('email_'.$templateKey)
            ->label($label)
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->schema([
                TextInput::make('to')->email()->required()
                    ->default(fn (Quote|SalesOrder|PurchaseOrder $record) => app(DocumentMailer::class)->defaultRecipient($record)),
                TagsInput::make('cc')->placeholder('Add email')
                    ->default(fn () => array_filter([auth()->user()?->email])),
            ])
            ->modalDescription('The PDF is attached. Edit the wording under Settings > Email templates.')
            ->action(function (Quote|SalesOrder|PurchaseOrder $record, array $data) use ($templateKey) {
                try {
                    app(DocumentMailer::class)->send($record, $templateKey, $data['to'], $data['cc'] ?? []);
                    Notification::make()->success()->title("{$record->number} emailed to {$data['to']}")->send();
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Email failed')->body($e->getMessage())->persistent()->send();
                }
            });
    }
}
