<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Custom (non-catalogue) quote lines: courier, design work, services.
 * - A fixed sell price per line as an alternative to GP%.
 * - Active flag and monthly target for sales people.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['quote_lines', 'sales_order_lines'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('line_type')->default('catalogue')->after('sort'); // catalogue, courier, design, setup, service, other
                $t->decimal('unit_price_override', 12, 2)->nullable()->after('gp_percent');
            });
        }

        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_active')->default(true)->after('commission_percent');
            $t->decimal('monthly_target', 14, 2)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        foreach (['quote_lines', 'sales_order_lines'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['line_type', 'unit_price_override']));
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_active', 'monthly_target']));
    }
};
