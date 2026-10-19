<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->unique()->after('name');
            $table->enum('role', ['owner', 'kasir'])->default('kasir')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('price_retail');
            $table->unsignedBigInteger('price_3kg');
            $table->unsignedBigInteger('price_5kg');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('product_price_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price_retail');
            $table->unsignedBigInteger('price_3kg');
            $table->unsignedBigInteger('price_5kg');
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('purchased_at');
            $table->decimal('gross_weight_kg', 10, 3);
            $table->unsignedBigInteger('price_per_kg');
            $table->unsignedBigInteger('total_cost');
            $table->text('notes')->nullable();
            $table->enum('status', ['open', 'finalized'])->default('open');
            $table->unsignedBigInteger('cost_per_clean_kg')->nullable();
            $table->decimal('clean_weight_kg', 10, 3)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('clean_weight_kg', 10, 3);
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->date('cleaned_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'cleaned_at', 'id']);
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            $table->enum('type', ['in_clean', 'sale', 'void_return', 'count_adjust']);
            $table->decimal('weight_kg', 10, 3);
            $table->unsignedBigInteger('unit_cost')->nullable();
            $table->string('ref_type')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'occurred_at']);
            $table->index('lot_id');
        });

        Schema::create('stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('counted_weight_kg', 10, 3);
            $table->decimal('system_weight_kg', 10, 3);
            $table->decimal('diff_kg', 10, 3);
            $table->unsignedBigInteger('unit_cost_avg');
            $table->bigInteger('loss_value');
            $table->timestamp('counted_at');
            $table->foreignId('counted_by')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_no')->unique();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->enum('customer_category', ['ecer', 'grosir']);
            $table->string('customer_name')->nullable();
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount')->default(0);
            $table->unsignedBigInteger('total');
            $table->enum('payment_method', ['cash', 'qris']);
            $table->enum('status', ['completed', 'void'])->default('completed');
            $table->timestamp('occurred_at');
            $table->text('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index('occurred_at');
            $table->index(['status', 'occurred_at']);
            $table->index(['cashier_id', 'occurred_at']);
        });

        Schema::create('transaction_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->decimal('weight_kg', 10, 3);
            $table->enum('price_tier', ['retail', '3kg', '5kg']);
            $table->unsignedBigInteger('price_per_kg');
            $table->unsignedBigInteger('line_total');
            $table->unsignedBigInteger('cogs');
            $table->timestamps();
        });

        Schema::create('sale_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            $table->decimal('weight_kg', 10, 3);
            $table->unsignedBigInteger('unit_cost');
            $table->boolean('is_shortfall')->default(false);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('amount');
            $table->date('spent_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('sale_allocations');
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('product_price_histories');
        Schema::dropIfExists('products');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'role', 'is_active']);
        });
    }
};
