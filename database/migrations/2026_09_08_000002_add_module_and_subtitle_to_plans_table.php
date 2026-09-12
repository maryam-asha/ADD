<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->foreignId('plan_module_id')
                ->nullable()
                ->after('id')
                ->constrained('plan_modules')
                ->nullOnDelete();
            $table->json('subtitle')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_module_id');
            $table->dropColumn('subtitle');
        });
    }
};
