<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coworking session a consumption happened during, when there was one
 * (docs/decisions/coworking-self-service-sessions.md) — what the session
 * report lists. Nullable: consumption outside any session, and company
 * consumption, stay unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_consumptions', function (Blueprint $table) {
            $table->nullableMorphs('sessionable');
        });
    }

    public function down(): void
    {
        Schema::table('resource_consumptions', function (Blueprint $table) {
            $table->dropMorphs('sessionable');
        });
    }
};
