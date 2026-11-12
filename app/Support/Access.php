<?php

namespace App\Support;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may see what. Two levels:
 *  - Admin: everything (costs for all reps, commission, reports, settings, suppliers, team).
 *  - Sales: quoting and the order process; commission and figures for their own work only.
 */
class Access
{
    public static function user(): ?User
    {
        return auth()->user();
    }

    public static function isAdmin(): bool
    {
        return self::user()?->isAdminOf(Filament::getTenant()) ?? false;
    }

    /** Commission on a quote / order is visible to admins and to the rep it belongs to. */
    public static function canSeeCommission(?Model $document = null): bool
    {
        if (self::isAdmin()) {
            return true;
        }
        $repId = $document?->sales_rep_id ?? $document?->quote?->sales_rep_id ?? $document?->salesOrder?->sales_rep_id;

        return $repId !== null && (int) $repId === (int) self::user()?->id;
    }
}
