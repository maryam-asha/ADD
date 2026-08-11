<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mobile client error/crash reports (Flutter/native member app), reported
     * via an unauthenticated ingestion endpoint (docs/superpowers/specs/
     * 2026-08-11-mobile-error-logging-design.md). `user_id` is client-supplied
     * and unverified on that endpoint, so it deliberately carries no FK
     * constraint — it's a reference value only, not a trustworthy relation.
     * `platform` is a plain string backing a PHP enum cast on the model
     * (App\Domain\Identity\Enums\ErrorLogPlatform), same pattern as
     * `private_office_requests.status`.
     */
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('error_type')->index();
            $table->text('message');
            $table->longText('stack_trace')->nullable();
            $table->string('app_version')->nullable();
            $table->string('build_number')->nullable();
            $table->string('platform', 10)->nullable()->index();
            $table->string('os')->nullable();
            $table->string('device')->nullable();
            $table->string('screen')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->timestamp('occurred_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
