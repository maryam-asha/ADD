<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_base');
        });

        // Set USD (the base currency) as default by default
        DB::table('currencies')->where('is_base', true)->update(['is_default' => true]);

        // If no row has is_base=true, mark 'USD' as default if it exists
        DB::table('currencies')->where('code', 'USD')->update(['is_default' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
