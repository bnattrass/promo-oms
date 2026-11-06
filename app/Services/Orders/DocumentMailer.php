<?php

namespace App\Services\Orders;

use App\Enums\PurchaseOrderStatus;
use App\Enums\QuoteStatus;
use App\Mail\DocumentMail;
use App\Models\Activity;
use App\Models\EmailTemplate;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/** Emails a document as a PDF attachment using the company's email template. */
class DocumentMailer
{
    public function __construct(private readonly DocumentPdf $pdf) {}

    public function send(Quote|SalesOrder|PurchaseOrder $document, string $templateKey, ?string $to = null, array $cc = []): void
    {
        $document->loadMissing('company');
        $to ??= $this->defaultRecipient($document);
        if (blank($to)) {
            throw new RuntimeException('No email address to send to.');
        }

        $template = EmailTemplate::where('company_id', $document->company_id)->where('key', $templateKey)->where('is_active', true)->first();
        $data = $this->placeholders($document);
        $rendered = $template?->render($data) ?? [
            'subject' => "{$document->company->name}: {$document->number}",
            'body' => "Please find {$document->number} attached.\n\nKind regards\n{$document->company->name}",
        ];

        Mail::to($to)->cc(array_filter($cc))->send(new DocumentMail(
            subjectLine: $rendered['subject'],
            bodyText: $rendered['body'],
            attachmentData: $this->pdf->render($document),
            attachmentName: $this->pdf->filename($document),
            fromCompany: $document->company,
        ));

        Activity::log($document, 'email_sent', "Emailed {$document->number} to {$to}", ['template' => $templateKey]);

        if ($document instanceof Quote && $templateKey === 'quote_sent') {
            $document->update([
                'status' => $document->status === QuoteStatus::Draft ? QuoteStatus::Sent : $document->status,
                'sent_at' => now(),
                'next_follow_up_at' => now()->addWeekdays($document->company->quote_follow_up_days ?: 3),
            ]);
        }
        if ($document instanceof PurchaseOrder && $document->status === PurchaseOrderStatus::Draft) {
            $document->update(['status' => PurchaseOrderStatus::Sent, 'sent_at' => now()]);
        }
    }

    public function defaultRecipient(Quote|SalesOrder|PurchaseOrder $document): ?string
    {
        if ($document instanceof PurchaseOrder) {
            return $document->supplier?->order_email;
        }

        return $document->contact?->email ?? $document->customer?->email;
    }

    public function placeholders(Quote|SalesOrder|PurchaseOrder $document): array
    {
        $money = fn ($v) => 'R '.number_format((float) $v, 2, '.', ' ');

        $base = [
            'company' => ['name' => $document->company->name, 'phone' => $document->company->phone, 'email' => $document->company->email],
            'document' => ['number' => $document->number, 'total' => $money($document->total)],
            'user' => ['name' => auth()->user()?->name],
        ];

        if ($document instanceof PurchaseOrder) {
            return $base + ['supplier' => ['name' => $document->supplier?->name, 'contact' => $document->supplier?->contact_person]];
        }

        return $base + [
            'customer' => ['name' => $document->customer?->name],
            'contact' => ['name' => $document->contact?->name ?? $document->customer?->name],
            'rep' => ['name' => $document->salesRep?->name, 'email' => $document->salesRep?->email],
            'quote' => $document instanceof Quote ? ['number' => $document->number, 'valid_until' => $document->valid_until?->format('j M Y')] : [],
            'order' => $document instanceof SalesOrder ? [
                'number' => $document->number,
                'deposit' => $money($document->deposit_required),
                'deposit_outstanding' => $money($document->depositOutstanding()),
            ] : [],
        ];
    }
}
