<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('rate_to_base');
            $table->foreignId('suggestion_id')->nullable()->after('source')
                ->constrained('exchange_rate_suggestions')->nullOnDelete();
        });

        // Explicit, matching the decision doc: every row that existed before
        // this feature landed is a manual entry by definition.
        DB::table('exchange_rates')->update(['source' => 'manual']);
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suggestion_id');
            $table->dropColumn('source');
        });
    }
};
