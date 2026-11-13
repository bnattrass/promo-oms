<?php

namespace App\Models\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Gives a document (quote, SO, PO) a sequential number per company, e.g. QT00042.
 * Models using this define documentPrefixColumn(): the column on companies holding the prefix.
 *
 * The company can set a "next number" per document type (to carry on from an old system)
 * and the number of digits. The next number is whichever is higher: the setting, or the
 * highest existing number + 1, so a number is never issued twice.
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
                $company = $model->company;
                $prefix = (string) ($company?->{static::documentPrefixColumn()} ?? '');
                $digits = max(1, (int) ($company?->document_number_digits ?: 5));

                // withoutGlobalScopes() also includes soft-deleted documents, so numbers are never reused.
                $highest = static::withoutGlobalScopes()
                    ->where('company_id', $model->company_id)
                    ->lockForUpdate()
                    ->pluck('number')
                    ->map(fn ($n) => static::sequenceOf((string) $n, $prefix))
                    ->max() ?? 0;

                $next = max($highest + 1, (int) ($company?->{static::documentNextNumberColumn()} ?? 0), 1);

                do {
                    $number = $prefix.str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
                    $next++;
                } while (static::withoutGlobalScopes()->where('company_id', $model->company_id)->where('number', $number)->exists());

                return $number;
            });
        });
    }

    /** The running number inside a document number, ignoring the prefix (so prefixes with digits, e.g. "Q26-", work). */
    public static function sequenceOf(string $number, string $prefix): int
    {
        if ($prefix !== '' && str_starts_with($number, $prefix)) {
            $number = substr($number, strlen($prefix));
        }

        return preg_match('/(\d+)\D*$/', $number, $m) ? (int) $m[1] : 0;
    }

    public static function documentNextNumberColumn(): string
    {
        return str_replace('_prefix', '_next_number', static::documentPrefixColumn());
    }

    abstract public static function documentPrefixColumn(): string;
}
