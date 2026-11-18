<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A kiosk device bound to the one coworking space it starts sessions on
 * (docs/decisions/coworking-self-service-sessions.md). The TOTP secret is
 * delivered to the device once, through a single-use pairing code, and is
 * never readable again through the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('space_id')->constrained('spaces')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('totp_secret')->nullable();
            $table->string('pairing_code_hash', 64)->nullable()->unique();
            $table->dateTime('pairing_code_expires_at')->nullable();
            $table->dateTime('paired_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosks');
    }
};
