<?php

namespace App\Models;

use App\Enums\CommissionStatus;
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
            'commission_paid_at' => 'date',
            'commission_paid_amount' => 'decimal:2',
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

    /** Commission is due once the order is invoiced (or closed), and paid when commission_paid_at is set. */
    public function commissionStatus(): CommissionStatus
    {
        if ($this->commission_paid_at) {
            return CommissionStatus::Paid;
        }

        return in_array($this->status, [SalesOrderStatus::Invoiced, SalesOrderStatus::Closed], true)
            ? CommissionStatus::Unpaid
            : CommissionStatus::NotDue;
    }

    /** Query scope: orders whose commission is in the given state. */
    public function scopeCommission(\Illuminate\Database\Eloquent\Builder $query, CommissionStatus|string $status): \Illuminate\Database\Eloquent\Builder
    {
        $status = $status instanceof CommissionStatus ? $status : CommissionStatus::from($status);
        $invoiced = [SalesOrderStatus::Invoiced->value, SalesOrderStatus::Closed->value];

        return match ($status) {
            CommissionStatus::Paid => $query->whereNotNull('commission_paid_at'),
            CommissionStatus::Unpaid => $query->whereNull('commission_paid_at')->whereIn('status', $invoiced),
            CommissionStatus::NotDue => $query->whereNull('commission_paid_at')->whereNotIn('status', $invoiced),
        };
    }

    /** Statuses that count as a sale (revenue): the order has been invoiced. */
    public const INVOICED_STATUSES = ['invoiced', 'closed'];

    /** Sales = invoiced orders, dated by invoice date (falls back to order date if none was entered). */
    public function scopeInvoicedBetween(\Illuminate\Database\Eloquent\Builder $query, string $from, string $to): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereIn('status', self::INVOICED_STATUSES)
            ->whereRaw('date(coalesce(invoice_date, order_date)) >= ?', [$from])
            ->whereRaw('date(coalesce(invoice_date, order_date)) <= ?', [$to]);
    }

    /** Confirmed orders not yet invoiced (the order book). */
    public function scopeNotYetInvoiced(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotIn('status', [...self::INVOICED_STATUSES, SalesOrderStatus::Cancelled->value]);
    }

    public function salesDate(): ?\Illuminate\Support\Carbon
    {
        return $this->invoice_date ?? $this->order_date;
    }

    public function commissionPaidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commission_paid_by');
    }

    public function depositOutstanding(): float
    {
        return max(0, round((float) $this->deposit_required - (float) $this->deposit_received, 2));
    }
}
