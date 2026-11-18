<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * The access token this refresh token was minted alongside. Kept
             * so logging out of one device ends exactly that pair rather than
             * every session the member has — nullable because the access token
             * is deleted first when a session is rotated or revoked, and the
             * historical row is still worth keeping for the audit trail.
             */
            $table->foreignId('access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();

            // SHA-256 hex of the token handed to the client. Stored hashed for
            // the same reason Sanctum hashes its own: a leaked database row
            // must not be a usable credential.
            $table->string('token_hash', 64)->unique();

            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
