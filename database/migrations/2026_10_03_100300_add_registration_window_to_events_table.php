<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When registration is accepted, and an admin's word that it no longer is.
     *
     * Both window ends are optional: an unset open means "as soon as the
     * event is published", an unset close means "until the event starts" —
     * so every existing row keeps a sensible window without a backfill.
     *
     * `is_marked_full` is the only way an external event can say it is full:
     * its registrations live in someone else's form, so we have no seats to
     * count. It closes an in-app event too, whatever its capacity.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('registration_opens_at')->nullable()->after('end_at');
            $table->timestamp('registration_closes_at')->nullable()->after('registration_opens_at');
            $table->boolean('is_marked_full')->default(false)->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['registration_opens_at', 'registration_closes_at', 'is_marked_full']);
        });
    }
};
