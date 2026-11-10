<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_counts', 'purchase_id')) {
            Schema::table('stock_counts', function (Blueprint $table): void {
                $table->foreignId('purchase_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            });
        }
        $this->backfillUnambiguousBatchCounts();
        if (Schema::hasColumn('purchases', 'closed_at')) {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->dropColumn('closed_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchases', 'closed_at')) {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->timestamp('closed_at')->nullable();
            });
        }
        if (Schema::hasColumn('stock_counts', 'purchase_id')) {
            Schema::table('stock_counts', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('purchase_id');
            });
        }
    }

    private function backfillUnambiguousBatchCounts(): void
    {
        $counts = DB::table('stock_counts')->whereNull('purchase_id')->orderBy('counted_at')->orderBy('id')->get();
        $groups = [];

        foreach ($counts as $count) {
            $product = DB::table('products')->where('id', $count->product_id)->first(['jenis']);
            if ($product === null) {
                continue;
            }

            $purchasedAt = Carbon::parse($count->counted_at, 'UTC')->setTimezone('Asia/Jakarta')->toDateString();
            $purchases = DB::table('purchases')->whereDate('purchased_at', $purchasedAt)
                ->where('jenis', $product->jenis)->whereNull('deleted_at')->get(['id', 'gross_weight_kg', 'price_per_kg', 'purchased_at']);
            if ($purchases->count() !== 1) {
                continue;
            }

            $purchase = $purchases->first();
            DB::table('stock_counts')->where('id', $count->id)->update(['purchase_id' => $purchase->id]);
            $key = $purchase->id.':'.$count->product_id;
            $groups[$key] ??= ['purchase' => $purchase, 'product_id' => (int) $count->product_id, 'count_ids' => []];
            $groups[$key]['count_ids'][] = (int) $count->id;
        }

        foreach ($groups as $group) {
            $countIds = $group['count_ids'];
            $hasUnassignedNonCountMovement = DB::table('stock_movements')->where('product_id', $group['product_id'])
                ->whereNull('lot_id')->where(function ($query): void {
                    $query->whereNotIn('type', ['count_adjust', 'count_reversal'])
                        ->orWhereNotIn('ref_type', ['stock_count', 'stock_count_cancelled']);
                })->exists();
            if ($hasUnassignedNonCountMovement) {
                continue;
            }

            $latestCount = DB::table('stock_counts')->whereIn('id', $countIds)->where('status', 'active')
                ->orderByDesc('counted_at')->orderByDesc('id')->first();
            if ($latestCount === null || bccomp((string) $latestCount->counted_weight_kg, '0', 3) <= 0) {
                continue;
            }

            $lotId = DB::table('stock_lots')->where('purchase_id', $group['purchase']->id)
                ->where('product_id', $group['product_id'])->value('id');
            if ($lotId === null) {
                $lotId = DB::table('stock_lots')->insertGetId([
                    'purchase_id' => $group['purchase']->id,
                    'product_id' => $group['product_id'],
                    'weight_kg' => $latestCount->counted_weight_kg,
                    'sorted_at' => $group['purchase']->purchased_at,
                    'unit_cost' => $group['purchase']->price_per_kg,
                    'notes' => 'Stok display ditautkan dari hitung ulang lama',
                    'created_by' => $latestCount->counted_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('stock_movements')->whereIn('ref_id', $countIds)->whereIn('ref_type', ['stock_count', 'stock_count_cancelled'])
                ->where('product_id', $group['product_id'])->whereNull('lot_id')->update(['lot_id' => $lotId]);
        }
    }
};
