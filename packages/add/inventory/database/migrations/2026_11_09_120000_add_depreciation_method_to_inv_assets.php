<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How the asset is depreciated: straight_line | declining_balance.
        Schema::table('inv_assets', function (Blueprint $table) {
            $table->string('depreciation_method', 30)->default('straight_line')->after('useful_life_months');
        });
    }

    public function down(): void
    {
        Schema::table('inv_assets', function (Blueprint $table) {
            $table->dropColumn('depreciation_method');
        });
    }
};
