<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-scoped sales data: customers, quotes, sales orders, purchase orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('account_code')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('billing_address')->nullable();
            $table->text('delivery_address')->nullable();
            $table->string('payment_terms')->default('deposit'); // cod, deposit, 30_days
            $table->decimal('deposit_percent', 5, 2)->default(50);
            $table->decimal('default_gp_percent', 5, 2)->nullable(); // overrides company default
            $table->string('accounting_id')->nullable(); // e.g. Zoho Books contact_id
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'name']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('position')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference')->nullable(); // customer's own ref / event name
            $table->string('status')->default('draft'); // draft, sent, accepted, declined, expired, converted
            $table->date('quote_date');
            $table->date('valid_until')->nullable();
            $table->date('required_by')->nullable();
            $table->boolean('show_setup_separately')->default(true);
            $table->decimal('cost_total', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);   // sell, ex VAT
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);      // incl VAT
            $table->decimal('gp_amount', 14, 2)->default(0);
            $table->decimal('gp_percent', 6, 2)->default(0);
            $table->decimal('commission_total', 14, 2)->default(0);
            $table->text('notes')->nullable();          // printed on quote
            $table->text('internal_notes')->nullable(); // staff only
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        $lineColumns = function (Blueprint $table) {
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_code')->nullable(); // full code at time of quoting
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_cost', 12, 4)->default(0);       // product cost per unit
            $table->json('brandings')->nullable();                 // see App\Services\Pricing\LinePricer
            $table->decimal('branding_unit_cost', 12, 4)->default(0);
            $table->decimal('setup_cost', 12, 2)->default(0);
            $table->decimal('extra_cost', 12, 2)->default(0);      // courier, handling etc.
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->decimal('gp_percent', 6, 2)->default(0);
            $table->decimal('unit_price', 12, 2)->default(0);      // sell per unit ex VAT (excl. setup if shown separately)
            $table->decimal('setup_price', 12, 2)->default(0);     // sell price of setups
            $table->decimal('line_total', 14, 2)->default(0);      // sell ex VAT
            $table->decimal('gp_amount', 14, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->timestamps();
        };

        Schema::create('quote_lines', function (Blueprint $table) use ($lineColumns) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $lineColumns($table);
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sales_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_po_number')->nullable();
            $table->string('reference')->nullable();
            $table->string('status')->default('confirmed');
            // confirmed, awaiting_deposit, artwork, artwork_approved, ordered, in_production,
            // ready, partially_dispatched, dispatched, invoiced, closed, cancelled
            $table->date('order_date');
            $table->date('required_by')->nullable();
            $table->decimal('cost_total', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('gp_amount', 14, 2)->default(0);
            $table->decimal('gp_percent', 6, 2)->default(0);
            $table->decimal('commission_total', 14, 2)->default(0);
            $table->decimal('deposit_required', 14, 2)->default(0);
            $table->decimal('deposit_received', 14, 2)->default(0);
            $table->timestamp('deposit_received_at')->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) use ($lineColumns) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $lineColumns($table);
            $table->string('stage')->default('pending');
            // pending, artwork, artwork_approved, ordered, in_production, ready, dispatched
            $table->unsignedInteger('quantity_received')->default(0);
            $table->unsignedInteger('quantity_dispatched')->default(0);
            $table->unsignedInteger('quantity_invoiced')->default(0);
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft'); // draft, sent, acknowledged, partially_received, received, cancelled
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('supplier_reference')->nullable(); // supplier's order / job number
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_code')->nullable();
            $table->string('description');
            $table->json('brandings')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('setup_cost', 12, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->unsignedInteger('quantity_received')->default(0);
            $table->timestamps();
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key'); // quote_sent, quote_follow_up, purchase_order, deposit_reminder ...
            $table->string('name');
            $table->string('subject');
            $table->longText('body'); // supports {{ placeholders }}
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->morphs('subject');
            $table->string('type'); // note, status_change, email_sent, follow_up
            $table->text('description');
            $table->json('properties')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['activities', 'email_templates', 'purchase_order_lines', 'purchase_orders', 'sales_order_lines',
            'sales_orders', 'quote_lines', 'quotes', 'contacts', 'customers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
