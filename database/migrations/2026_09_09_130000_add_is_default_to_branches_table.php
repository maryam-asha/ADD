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
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active');
        });

        // Set the existing first branch (if any) as default
        $firstBranch = DB::table('branches')->orderBy('id')->first();
        if ($firstBranch) {
            DB::table('branches')->where('id', $firstBranch->id)->update(['is_default' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
