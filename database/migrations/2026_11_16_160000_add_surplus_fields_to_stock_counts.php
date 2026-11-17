<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->decimal('surplus_kg', 10, 3)->default(0);
            $table->unsignedBigInteger('surplus_value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table): void {
            $table->dropColumn(['surplus_kg', 'surplus_value']);
        });
    }
};
