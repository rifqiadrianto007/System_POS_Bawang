<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->where('jenis', 'merah')
            ->where(function ($query): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['putih %'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['bawang putih %']);
            })
            ->update(['jenis' => 'putih']);
    }

    public function down(): void
    {
        // Keep corrected product classifications when rolling back unrelated migrations.
    }
};
