<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Some events collect registrations somewhere else entirely — a Google
     * Form, a partner's own portal. When this is set the member is sent
     * there instead of registering here, so nothing lands in
     * `event_attendees`: no seat is taken, no approval is asked for, and no
     * points are charged. The column being present *is* the switch; a
     * separate mode enum would let the two disagree.
     *
     * Deliberately not a replacement for `has_registration_form`, which
     * still describes the questions an in-app registration asks.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('external_form_url', 2048)->nullable()->after('has_registration_form');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('external_form_url');
        });
    }
};
