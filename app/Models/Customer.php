<?php

namespace App\Models;

use App\Enums\PaymentTerms;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payment_terms' => PaymentTerms::class,
            'deposit_percent' => 'decimal:2',
            'default_gp_percent' => 'decimal:2',
        ];
    }

    public function salesRep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_rep_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function primaryContact(): ?Contact
    {
        return $this->contacts->firstWhere('is_primary', true) ?? $this->contacts->first();
    }

    public function requiresDeposit(): bool
    {
        return $this->payment_terms !== PaymentTerms::ThirtyDays && (float) $this->deposit_percent > 0;
    }
}
