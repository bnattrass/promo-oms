<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use BelongsToCompany;

    public const KEYS = [
        'quote_sent' => 'Quote sent to customer',
        'quote_follow_up' => 'Quote follow-up',
        'sales_order_confirmation' => 'Sales order confirmation',
        'deposit_reminder' => 'Deposit reminder',
        'purchase_order' => 'Purchase order to supplier',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Replace {{ placeholder }} tokens, e.g. {{ customer.name }} or {{ quote.number }}. */
    public function render(array $data): array
    {
        $replace = fn (string $text) => preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            fn ($m) => (string) data_get($data, $m[1], ''),
            $text,
        );

        return ['subject' => $replace($this->subject), 'body' => $replace($this->body)];
    }
}
