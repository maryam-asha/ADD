<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pending registration holds a seat but costs nothing, so an
     * abandoned one can block a paid event indefinitely — and several of
     * them, cheaply, is a way to fill an event without ever paying.
     *
     * The deadline is derived from the event's start rather than the
     * submission, so an application for a distant event stays alive as
     * long as it is useful and one for tomorrow expires quickly. Stored
     * rather than computed because the sweep must be able to find expired
     * rows by index, and because moving an event's date should not
     * silently re-open registrations that were already closed out.
     */
    public function up(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dateTime('pending_expires_at')->nullable()->after('approved_at');

            $table->index(['status', 'pending_expires_at'], 'event_attendees_pending_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('event_attendees', function (Blueprint $table) {
            $table->dropIndex('event_attendees_pending_expiry_idx');
            $table->dropColumn('pending_expires_at');
        });
    }
};
