<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest a member in a coworking session brings in. Reception records
     * them on arrival; they stay free for `guest.grace_minutes`, and if
     * reception has not checked them out by then the visit turns into a
     * walk-in billed from their arrival — on the guest's own account when
     * they have one, otherwise on the host's (`walkin_sessions.guest_visit_id`).
     * Rooms and offices never get here: their guests are the booking's.
     */
    public function up(): void
    {
        Schema::create('guest_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('guest_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('guest_name');
            $table->string('guest_phone');
            $table->foreignId('space_id')->constrained()->cascadeOnDelete();
            $table->dateTime('arrived_at');
            $table->dateTime('grace_ends_at');
            $table->string('status');
            $table->dateTime('host_declined_at')->nullable();
            $table->dateTime('left_at')->nullable();
            $table->dateTime('converted_at')->nullable();
            $table->foreignId('walkin_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'grace_ends_at']);
            $table->index(['guest_phone', 'arrived_at']);
        });

        Schema::table('walkin_sessions', function (Blueprint $table) {
            // Set only on a guest's session billed to their host: user_id is
            // then the host (who pays), not the person in the seat.
            $table->foreignId('guest_visit_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('walkin_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guest_visit_id');
        });

        Schema::dropIfExists('guest_visits');
    }
};
