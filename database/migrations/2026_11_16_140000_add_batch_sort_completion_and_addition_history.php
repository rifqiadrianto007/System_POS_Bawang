<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->timestamp('sorted_finished_at')->nullable();
            $table->foreignId('sorted_finished_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->decimal('cleaning_shrink_kg', 10, 3)->default(0);
            $table->unsignedBigInteger('cleaning_loss_value')->default(0);
            $table->boolean('needs_stock_reconciliation')->default(false);
        });

        DB::table('purchases')->update(['needs_stock_reconciliation' => true]);

        Schema::create('stock_additions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('weight_kg', 10, 3);
            $table->date('added_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['purchase_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_additions');
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sorted_finished_by');
            $table->dropColumn(['sorted_finished_at', 'cleaning_shrink_kg', 'cleaning_loss_value', 'needs_stock_reconciliation']);
        });
    }
};
