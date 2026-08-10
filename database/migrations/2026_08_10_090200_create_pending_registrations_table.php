<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sign-up in progress: validated, but not yet proven to belong to whoever
     * owns the phone. Parked here rather than in `users` so an unverified
     * submission can never be an account — nothing downstream has to remember
     * to exclude a half-built row from listings, counts, or wallet
     * provisioning, because there is no such row.
     */
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();

            // One in-flight sign-up per number; starting again overwrites it, so
            // the parked profile is always the one the live code belongs to.
            $table->string('phone')->unique();

            $table->string('name');

            /*
             * Deliberately NOT unique. The uniqueness that matters is on
             * `users.email`, checked at both steps. Enforcing it here as well
             * would let anyone park an address they don't own and block the
             * real owner from ever signing up with it — an easier denial than
             * the one it would prevent.
             */
            $table->string('email')->nullable();

            // Already hashed on the way in (the model casts it), so this table
            // never holds a usable plaintext credential.
            $table->string('password');

            // Set to the moment the code expires. The two are halves of one
            // intent and are worthless apart, so they die together.
            $table->dateTime('expires_at')->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
