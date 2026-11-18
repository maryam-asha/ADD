<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('preferred_currency')->update(['preferred_currency' => 'SYP']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->default('SYP')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_currency', 3)->nullable()->default(null)->change();
        });
    }
};
