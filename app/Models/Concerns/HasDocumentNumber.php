<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Gives a document (quote, SO, PO) a sequential number per company, e.g. QT00042.
 * Models using this define documentPrefixColumn(): the column on companies holding the prefix.
 */
trait HasDocumentNumber
{
    public static function bootHasDocumentNumber(): void
    {
        static::creating(function (Model $model) {
            if (filled($model->number)) {
                return;
            }

            $model->number = DB::transaction(function () use ($model) {
                $prefix = $model->company?->{static::documentPrefixColumn()} ?? '';

                // withoutGlobalScopes() also includes soft-deleted documents, so numbers are never reused.
                $last = static::withoutGlobalScopes()
                    ->where('company_id', $model->company_id)
                    ->lockForUpdate()
                    ->orderByDesc('id')
                    ->value('number');

                $next = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;

                return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            });
        });
    }

    abstract public static function documentPrefixColumn(): string;
}
