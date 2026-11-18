<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An event runs on a schedule, not in one unbroken stretch.
 *
 * A three-day exhibition that opens 09:00–17:00, then 10:00–14:00, then
 * 16:00–21:00 could not be expressed at all: `events.start_at`/`end_at` is a
 * single interval, so the only options were to lie about the hours or to
 * create three separate events and split the registrations between them.
 *
 * `start_at`/`end_at` stay on `events` and stay authoritative for ordering,
 * filtering, the registration deadline and the card. They become *derived* —
 * the earliest session's start and the latest session's end — rather than
 * typed. Every existing query, the public API shape, and the member app all
 * keep working untouched; see EventSchedule for where that is recomputed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')
                ->constrained()
                ->cascadeOnDelete();

            // Instants, like events.start_at — the Form Request interprets the
            // admin's wall clock before it gets here. See
            // docs/decisions/event-approval-and-custom-forms.md.
            $table->dateTime('start_at');
            $table->dateTime('end_at');

            // Optional, for a session that is not simply "day 2": a workshop
            // track, a keynote, a closing ceremony.
            $table->json('title')->nullable();

            $table->timestamps();

            // Every read is "this event's sessions, in order".
            $table->index(['event_id', 'start_at'], 'event_sessions_event_start_idx');
        });

        // Existing events become single-session events, so nothing has to
        // special-case "an event with no sessions". Test rows or not, leaving
        // them empty would make the derived start_at null on the next save.
        DB::statement('
            INSERT INTO event_sessions (event_id, start_at, end_at, created_at, updated_at)
            SELECT id, start_at, end_at, created_at, updated_at
            FROM events
            WHERE start_at IS NOT NULL AND end_at IS NOT NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sessions');
    }
};
