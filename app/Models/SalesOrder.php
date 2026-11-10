<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use BelongsToCompany, HasDocumentNumber, SoftDeletes;

    protected $guarded = [];

    public static function documentPrefixColumn(): string
    {
        return 'sales_order_prefix';
    }

    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'order_date' => 'date',
            'required_by' => 'date',
            'deposit_required' => 'decimal:2',
            'show_images' => 'boolean',
            'deposit_received' => 'decimal:2',
            'deposit_received_at' => 'datetime',
            'invoice_date' => 'date',
            'invoiced_amount' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('sort');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->latest();
    }

    public function customerPurchaseOrder(): ?Attachment
    {
        return $this->attachments()->where('type', 'customer_po')->first();
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest();
    }

    public function depositOutstanding(): float
    {
        return max(0, round((float) $this->deposit_required - (float) $this->deposit_received, 2));
    }
}
