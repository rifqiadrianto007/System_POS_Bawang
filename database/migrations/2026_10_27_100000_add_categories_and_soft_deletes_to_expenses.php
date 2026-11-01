<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('category')->nullable()->after('name');
            $table->timestamp('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->restrictOnDelete();
        });

        DB::table('expenses')->whereNull('category')->update(['category' => 'lainnya']);

        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('category')->nullable(false)->change();
            $table->index('spent_at');
            $table->index(['category', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropForeign(['deleted_by']);
            $table->dropIndex(['spent_at']);
            $table->dropIndex(['category', 'spent_at']);
            $table->dropColumn(['category', 'deleted_at', 'deleted_by']);
        });
    }
};
