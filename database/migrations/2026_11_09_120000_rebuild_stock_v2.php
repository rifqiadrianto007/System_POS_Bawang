<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'jenis')) {
                $table->string('jenis')->default('merah');
            }
            if (! Schema::hasColumn('products', 'last_unit_cost')) {
                $table->decimal('last_unit_cost', 12, 3)->nullable();
            }
            if (Schema::hasColumn('products', 'price_3kg')) {
                $table->dropColumn('price_3kg');
            }
        });
        Schema::table('product_price_histories', function (Blueprint $table): void {
            if (Schema::hasColumn('product_price_histories', 'price_3kg')) {
                $table->dropColumn('price_3kg');
            }
        });
        Schema::table('purchases', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchases', 'code')) {
                $table->string('code')->nullable()->unique();
            }
            if (! Schema::hasColumn('purchases', 'jenis')) {
                $table->string('jenis')->default('merah');
            }
            if (! Schema::hasColumn('purchases', 'closed_at')) {
                $table->timestamp('closed_at')->nullable();
            }
            if (! Schema::hasColumn('purchases', 'deleted_at')) {
                $table->softDeletes();
            }
        });
        $sequences = [];
        foreach (DB::table('purchases')->orderBy('purchased_at')->orderBy('id')->get(['id', 'purchased_at', 'code']) as $purchase) {
            if ($purchase->code !== null) {
                continue;
            }
            $date = Carbon::parse($purchase->purchased_at)->format('ymd');
            $sequence = ($sequences[$date] ?? 0) + 1;
            $sequences[$date] = $sequence;
            DB::table('purchases')->where('id', $purchase->id)->update(['code' => 'KLK-'.$date.'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT)]);
        }
        Schema::table('purchases', function (Blueprint $table): void {
            $table->string('code')->nullable(false)->change();
        });
        if (Schema::hasColumn('purchases', 'status')) {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->dropColumn(['status', 'cost_per_clean_kg', 'clean_weight_kg', 'finalized_at']);
            });
        }
        if (Schema::hasColumn('stock_lots', 'cleaned_at')) {
            // The old composite index also supports the product_id foreign key.
            // Preserve FK coverage before replacing its cleaned_at ordering.
            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->index('product_id', 'stock_lots_product_id_fk_support_index');
            });
        }
        Schema::table('stock_lots', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_lots', 'cleaned_at')) {
                $table->dropIndex('stock_lots_product_id_cleaned_at_id_index');
            }
            if (! Schema::hasColumn('stock_lots', 'weight_kg')) {
                $table->decimal('weight_kg', 10, 3)->nullable();
            }
            if (! Schema::hasColumn('stock_lots', 'sorted_at')) {
                $table->date('sorted_at')->nullable();
            }
            if (! Schema::hasColumn('stock_lots', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            }
        });
        if (Schema::hasColumn('stock_lots', 'clean_weight_kg')) {
            DB::table('stock_lots')->update(['weight_kg' => DB::raw('clean_weight_kg'), 'sorted_at' => DB::raw('cleaned_at')]);
            Schema::table('stock_lots', function (Blueprint $table): void {
                $table->dropColumn(['clean_weight_kg', 'cleaned_at']);
            });
        }
        DB::table('stock_lots')->whereNull('unit_cost')->update([
            'unit_cost' => DB::raw('(SELECT price_per_kg FROM purchases WHERE purchases.id = stock_lots.purchase_id)'),
        ]);
        DB::table('stock_lots')->whereNull('created_by')->update([
            'created_by' => DB::raw('(SELECT created_by FROM purchases WHERE purchases.id = stock_lots.purchase_id)'),
        ]);
        Schema::table('stock_lots', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_lots', 'weight_kg')) {
                $table->decimal('weight_kg', 10, 3)->nullable(false)->change();
            }
            if (Schema::hasColumn('stock_lots', 'sorted_at')) {
                $table->date('sorted_at')->nullable(false)->change();
            }
            if (Schema::hasColumn('stock_lots', 'unit_cost')) {
                $table->unsignedBigInteger('unit_cost')->nullable(false)->change();
            }
            if (Schema::hasColumn('stock_lots', 'created_by')) {
                $table->foreignId('created_by')->nullable(false)->change();
            }
            if (! Schema::hasColumn('stock_lots', 'deleted_at')) {
                $table->softDeletes();
            }
            $table->index(['product_id', 'sorted_at', 'id']);
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'value_delta')) {
                $table->bigInteger('value_delta')->default(0);
            }
            if (Schema::hasColumn('stock_movements', 'unit_cost')) {
                $table->dropColumn('unit_cost');
            }
        });
        if (Schema::hasColumn('stock_movements', 'type')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->dropColumn('type');
            });
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->string('type');
            });
        }
        Schema::table('transaction_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('transaction_items', 'shortfall_kg')) {
                $table->decimal('shortfall_kg', 10, 3)->default(0);
            }
        });
        Schema::dropIfExists('sale_allocations');
    }

    public function down(): void
    {
        throw new RuntimeException('Migrasi stok v2 tidak dapat dibalik otomatis.');
    }
};
