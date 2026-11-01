<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_counts', 'status')) {
            Schema::table('stock_counts', function (Blueprint $table): void {
                $table->string('status')->default('active')->after('notes');
            });
        }

        if (! Schema::hasColumn('stock_counts', 'cancelled_at')) {
            Schema::table('stock_counts', function (Blueprint $table): void {
                $table->timestamp('cancelled_at')->nullable()->after('status');
            });
        }

        if (! Schema::hasColumn('stock_counts', 'cancelled_by_name')) {
            Schema::table('stock_counts', function (Blueprint $table): void {
                $table->string('cancelled_by_name')->nullable()->after('cancelled_at');
            });
        }

        if (! Schema::hasColumn('stock_counts', 'cancel_reason')) {
            Schema::table('stock_counts', function (Blueprint $table): void {
                $table->string('cancel_reason')->nullable()->after('cancelled_by_name');
            });
        }
    }

    public function down(): void
    {
        foreach (['cancel_reason', 'cancelled_by_name', 'cancelled_at', 'status'] as $column) {
            if (Schema::hasColumn('stock_counts', $column)) {
                Schema::table('stock_counts', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
