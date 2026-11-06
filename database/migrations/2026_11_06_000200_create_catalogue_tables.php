<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier catalogue. Shared by all companies: one Amrod sync feeds everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('driver')->nullable(); // amrod, null = manual supplier (no API)
            $table->text('credentials')->nullable(); // encrypted JSON, see Supplier model cast
            $table->json('settings')->nullable();
            $table->string('order_email')->nullable(); // where POs are emailed
            $table->string('accounts_email')->nullable();
            $table->string('phone')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('sync_enabled')->default(false);
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_changes_sync_at')->nullable();
            $table->timestamp('last_stock_sync_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('supplier_categories')->nullOnDelete();
            $table->string('external_id');
            $table->string('code')->nullable();
            $table->string('name');
            $table->string('path')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'external_id']);
        });

        Schema::create('supplier_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('image_url', 1024)->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'code']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('simple_code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('product'); // product, giftset
            $table->foreignId('category_id')->nullable()->constrained('supplier_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('supplier_brands')->nullOnDelete();
            $table->string('gender')->nullable();
            $table->json('images')->nullable();
            $table->string('branding_guide_url', 1024)->nullable();
            $table->json('required_positions')->nullable();
            $table->json('no_co_branding_positions')->nullable();
            $table->json('related_codes')->nullable();   // companion / related / matching
            $table->json('specs')->nullable();       
            $table->json('raw')->nullable();              // full supplier record, for remapping
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'simple_code']);
            $table->index('name');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('full_code');
            $table->string('colour_code')->nullable();
            $table->string('colour_name')->nullable();
            $table->string('colour_hex', 20)->nullable();
            $table->string('size')->nullable();
            $table->decimal('cost_price', 12, 2)->nullable(); // ex VAT, supplier tier price
            $table->integer('stock_on_hand')->default(0);
            $table->json('stock_incoming')->nullable(); // [{qty, eta}]
            $table->string('image_url', 1024)->nullable();
            $table->json('raw')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('price_synced_at')->nullable();
            $table->timestamp('stock_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'full_code']);
        });

        Schema::create('branding_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->unsignedTinyInteger('multiplier')->default(1); // e.g. left OR right chest = 2
            $table->boolean('is_required')->default(false);
            $table->boolean('is_no_co_branding')->default(false);
            $table->timestamps();
            $table->unique(['product_id', 'code']);
        });

        Schema::create('branding_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branding_position_id')->constrained()->cascadeOnDelete();
            $table->string('branding_code');
            $table->string('name');
            $table->integer('max_colours')->nullable();
            $table->string('max_print_size')->nullable();
            $table->json('excluded_colour_codes')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index('branding_code');
        });

        Schema::create('branding_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('branding_code');
            $table->string('branding_method')->nullable();
            $table->string('print_code')->nullable();
            $table->unsignedInteger('min_quantity');
            $table->integer('max_quantity'); // -1 = no upper limit
            $table->integer('number_of_colours'); // -2 = not colour based (e.g. embroidery)
            $table->decimal('setup', 12, 2)->default(0);
            $table->decimal('price', 12, 4)->default(0); // per unit
            $table->timestamps();
            $table->index(['supplier_id', 'branding_code']);
        });

        Schema::create('inclusive_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('product_code'); // simple code
            $table->string('position_code')->nullable();
            $table->string('branding_code')->nullable();
            $table->string('replacement_code')->nullable();
            $table->string('description')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'product_code']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->string('module');          // products, prices, stock, ...
            $table->string('mode');            // full, changes
            $table->string('status')->default('running'); // running, success, skipped, failed
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_deleted')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'module', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['sync_runs', 'inclusive_brandings', 'branding_prices', 'branding_methods', 'branding_positions',
            'product_variants', 'products', 'supplier_brands', 'supplier_categories', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
