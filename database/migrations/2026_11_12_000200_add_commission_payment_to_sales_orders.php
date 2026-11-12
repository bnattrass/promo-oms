<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Commission is earned on invoiced orders and paid to the rep later; track when and how it was paid. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->date('commission_paid_at')->nullable()->after('commission_total');
            $table->decimal('commission_paid_amount', 14, 2)->nullable()->after('commission_paid_at');
            $table->string('commission_paid_reference')->nullable()->after('commission_paid_amount');
            $table->foreignId('commission_paid_by')->nullable()->after('commission_paid_reference')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_paid_by');
            $table->dropColumn(['commission_paid_at', 'commission_paid_amount', 'commission_paid_reference']);
        });
    }
};
