<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('deposit_received_at');
            $table->date('invoice_date')->nullable()->after('invoice_number');
            $table->decimal('invoiced_amount', 14, 2)->nullable()->after('invoice_date'); // incl VAT
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn(['invoice_number', 'invoice_date', 'invoiced_amount']));
    }
};
