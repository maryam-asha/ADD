<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reminder's claim that it reached one person. The reminder runner finds
 * the same subject on every run; the unique (user, key) pair lets only the
 * first run send (NotificationService::sendToUserOnce). A table of its own
 * rather than a column on notification_logs, whose rows are push attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('dedupe_key', 191);
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_claims');
    }
};
