<?php

namespace App\Services\Orders;

use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesOrder;
use Barryvdh\DomPDF\Facade\Pdf;

/** Renders quotes, sales orders and purchase orders as PDF using resources/views/pdf/*. */
class DocumentPdf
{
    public function __construct(private readonly ProductImages $images) {}

    public function render(Quote|SalesOrder|PurchaseOrder $document): string
    {
        return Pdf::loadView($this->view($document), $this->data($document))->setPaper('a4')->output();
    }

    /** Internal costing sheet (cost, sell, GP per line) for a quote or sales order. */
    public function renderCosting(Quote|SalesOrder $document): string
    {
        $document->loadMissing('company', 'lines', 'customer', 'salesRep');

        return Pdf::loadView('pdf.costing', [
            'showCommission' => \App\Support\Access::canSeeCommission($document),
            'doc' => $document,
            'company' => $document->company,
            'money' => fn ($v) => 'R '.number_format((float) $v, 2, '.', ' '),
        ])->setPaper('a4', 'landscape')->output();
    }

    public function filename(Quote|SalesOrder|PurchaseOrder $document): string
    {
        return "{$document->number}.pdf";
    }

    private function view(Quote|SalesOrder|PurchaseOrder $document): string
    {
        return match (true) {
            $document instanceof Quote => 'pdf.quote',
            $document instanceof SalesOrder => 'pdf.sales-order',
            $document instanceof PurchaseOrder => 'pdf.purchase-order',
        };
    }

    private function data(Quote|SalesOrder|PurchaseOrder $document): array
    {
        $document->loadMissing('company', 'lines');
        if (! $document instanceof PurchaseOrder) {
            $document->loadMissing('customer', 'contact', 'salesRep');
        } else {
            $document->loadMissing('supplier', 'salesOrder.customer');
        }

        // Thumbnails for catalogue lines (quotes and sales orders, when switched on).
        $thumbs = [];
        if (! $document instanceof PurchaseOrder && ($document->show_images ?? true)) {
            $document->lines->loadMissing('variant.product', 'product');
            foreach ($document->lines as $line) {
                $thumbs[$line->id] = $this->images->dataUri($this->images->urlFor($line->variant, $line->product));
            }
        }

        return [
            'thumbs' => $thumbs,
            'doc' => $document,
            'company' => $document->company,
            'money' => fn ($v) => 'R '.number_format((float) $v, 2, '.', ' '),
        ];
    }
}
