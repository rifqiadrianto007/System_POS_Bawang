<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('month_start')->unique();
            $table->date('as_of_date');
            $table->decimal('display_kg', 10, 3)->default(0);
            $table->bigInteger('display_value')->default(0);
            $table->decimal('unsorted_kg', 10, 3)->default(0);
            $table->unsignedBigInteger('unsorted_value')->default(0);
            $table->bigInteger('inventory_value')->default(0);
            $table->timestamp('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_inventory_snapshots');
    }
};
