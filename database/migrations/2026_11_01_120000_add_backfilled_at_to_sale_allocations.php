<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sale_allocations', 'backfilled_at')) {
            Schema::table('sale_allocations', function (Blueprint $table): void {
                $table->timestamp('backfilled_at')->nullable()->after('is_shortfall');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_allocations', 'backfilled_at')) {
            Schema::table('sale_allocations', function (Blueprint $table): void {
                $table->dropColumn('backfilled_at');
            });
        }
    }
};
