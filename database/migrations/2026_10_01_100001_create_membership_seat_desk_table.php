<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The desks a Dedicated Desk subscription is tied to — as many as it holds
     * seats, since a company may buy several. That no two live subscriptions
     * share a desk is DedicatedDeskService's rule, not a key: an ended
     * subscription keeps its row as history.
     */
    public function up(): void
    {
        Schema::create('membership_seat_desk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membership_id')->constrained('memberships')->cascadeOnDelete();
            $table->foreignId('seat_desk_id')->constrained('seats_desks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['membership_id', 'seat_desk_id']);
            $table->index('seat_desk_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_seat_desk');
    }
};
