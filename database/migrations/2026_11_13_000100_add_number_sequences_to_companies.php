<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // "Start at" numbers so numbering can carry on from the old system.
            $table->unsignedInteger('quote_next_number')->nullable();
            $table->unsignedInteger('sales_order_next_number')->nullable();
            $table->unsignedInteger('purchase_order_next_number')->nullable();
            $table->unsignedTinyInteger('document_number_digits')->default(5);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['quote_next_number', 'sales_order_next_number', 'purchase_order_next_number', 'document_number_digits']);
        });
    }
};
