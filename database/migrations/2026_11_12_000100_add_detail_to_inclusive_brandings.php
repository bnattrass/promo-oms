<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Amrod inclusive branding rows are per product colour, with allowed positions, a minimum quantity and validity dates. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inclusive_brandings', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('supplier_id');
            $table->string('colour_code')->nullable()->after('product_code');
            $table->json('positions')->nullable()->after('position_code');        // e.g. ["A","B","C"]; null = any
            $table->integer('number_of_colours')->nullable()->after('replacement_code');
            $table->integer('min_quantity')->default(1)->after('number_of_colours');
            $table->dateTime('valid_from')->nullable()->after('min_quantity');
            $table->dateTime('valid_to')->nullable()->after('valid_from');
            $table->index(['supplier_id', 'product_code', 'branding_code']);
        });
    }

    public function down(): void
    {
        Schema::table('inclusive_brandings', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'product_code', 'branding_code']);
            $table->dropColumn(['external_id', 'colour_code', 'positions', 'number_of_colours', 'min_quantity', 'valid_from', 'valid_to']);
        });
    }
};
