<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Only people ticked as sales reps appear in rep pickers, rep reports and commission. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_sales_rep')->default(false)->after('is_active');
        });

        // Existing users: reps are those with a sales role, a commission rate or quotes/orders assigned.
        $ids = DB::table('company_user')->where('role', 'sales')->pluck('user_id')
            ->merge(DB::table('users')->where('commission_percent', '>', 0)->pluck('id'))
            ->merge(DB::table('quotes')->whereNotNull('sales_rep_id')->pluck('sales_rep_id'))
            ->merge(DB::table('sales_orders')->whereNotNull('sales_rep_id')->pluck('sales_rep_id'))
            ->unique()->values();
        DB::table('users')->whereIn('id', $ids)->update(['is_sales_rep' => true]);

        // Older roles (art, production, accounts) become Sales-level access.
        DB::table('company_user')->whereNotIn('role', ['admin', 'sales'])->update(['role' => 'sales']);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_sales_rep'));
    }
};
