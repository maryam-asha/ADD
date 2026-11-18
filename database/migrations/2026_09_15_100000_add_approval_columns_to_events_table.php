<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some events admit anyone who signs up; others need a person to look
     * at the registration first. `requires_approval` mirrors
     * `spaces.requires_approval` — a per-entity toggle with no global
     * fallback, because there is no sensible district-wide default.
     *
     * `has_registration_form` is denormalized on purpose: the event card
     * has to say whether a form exists, and a count query per card on a
     * paginated list is a poor trade for one boolean.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('capacity');
            $table->boolean('has_registration_form')->default(false)->after('requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['requires_approval', 'has_registration_form']);
        });
    }
};
