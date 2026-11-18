<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a booking on this space may be extended at all
     * (docs/decisions/booking-outcomes-and-extension.md). True by default so
     * every existing space keeps the extension it already had.
     */
    public function up(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->boolean('allows_extension')->default(true)->after('requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('spaces', function (Blueprint $table) {
            $table->dropColumn('allows_extension');
        });
    }
};
