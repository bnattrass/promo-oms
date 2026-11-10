<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Amrod sends -1 as minQuantity for "no minimum" (e.g. STP-C Silicone Transfer), which the
 * original UNSIGNED column rejected on MySQL and aborted the whole branding price import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branding_prices', function (Blueprint $table) {
            $table->integer('min_quantity')->change();
        });
    }

    public function down(): void
    {
        Schema::table('branding_prices', function (Blueprint $table) {
            $table->unsignedInteger('min_quantity')->change();
        });
    }
};
