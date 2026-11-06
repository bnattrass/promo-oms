<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('registration_number')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('quote_prefix', 10)->default('QT');
            $table->string('sales_order_prefix', 10)->default('SO');
            $table->string('purchase_order_prefix', 10)->default('PO');
            $table->decimal('default_gp_percent', 5, 2)->default(35);
            $table->decimal('vat_percent', 5, 2)->default(15);
            $table->unsignedSmallInteger('quote_valid_days')->default(14);
            $table->unsignedSmallInteger('quote_follow_up_days')->default(3);
            $table->text('quote_terms')->nullable();
            $table->text('bank_details')->nullable();
            $table->timestamps();
        });

        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('sales'); // admin, sales, art, production, accounts
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->decimal('commission_percent', 5, 2)->default(0)->after('is_super_admin');
            $table->string('phone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'commission_percent', 'phone']);
        });
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
    }
};
