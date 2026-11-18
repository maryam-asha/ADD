<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberate rollback, not a correction of a mistake — see
     * docs/decisions/district-removed.md. District was a permanent
     * single-row umbrella concept; Branch already carries its own name and
     * geography and is itself the unit that scales for a new city or
     * region, so District added a decorative FK and no real filtering or
     * query capability. A new migration reverses the two Phase 1
     * migrations that introduced it, rather than editing them in place —
     * they may already have run elsewhere.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('district_id');
        });

        Schema::dropIfExists('districts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('districts', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('district_id')->nullable()->after('id')->constrained('districts')->nullOnDelete();
        });
    }
};
