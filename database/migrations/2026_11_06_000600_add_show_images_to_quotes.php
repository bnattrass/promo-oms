<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('show_images')->default(true)->after('show_setup_separately');
        });
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->boolean('show_images')->default(true)->after('required_by');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', fn (Blueprint $t) => $t->dropColumn('show_images'));
        Schema::table('sales_orders', fn (Blueprint $t) => $t->dropColumn('show_images'));
    }
};
