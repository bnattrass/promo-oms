<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Only one main contact per customer.
        static::saved(function (Contact $contact) {
            if ($contact->is_primary) {
                static::where('customer_id', $contact->customer_id)->whereKeyNot($contact->getKey())->update(['is_primary' => false]);
            }
        });
    }

    public function label(): string
    {
        return $this->name.($this->position ? " ({$this->position})" : '');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
