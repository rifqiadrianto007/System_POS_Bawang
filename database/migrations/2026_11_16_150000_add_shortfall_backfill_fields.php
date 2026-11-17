<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_movements')->whereIn('type', ['sale', 'void_return'])
            ->whereNotNull('lot_id')->update(['lot_id' => null]);

        Schema::table('transaction_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('shortfall_unit_cost')->default(0);
            $table->decimal('shortfall_resolved_kg', 10, 3)->default(0);
        });

        DB::table('transaction_items')->where('shortfall_kg', '>', 0)->update([
            'shortfall_resolved_kg' => DB::raw('shortfall_kg'),
        ]);
    }

    public function down(): void
    {
        Schema::table('transaction_items', function (Blueprint $table): void {
            $table->dropColumn(['shortfall_unit_cost', 'shortfall_resolved_kg']);
        });
    }
};
