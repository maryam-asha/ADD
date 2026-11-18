<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed banner content for the reception kiosk
 * (docs/decisions/kiosk-display.md). `type` is a plain open string, not a
 * MySQL ENUM and not a PHP backed enum cast — a new kind of announcement is
 * a row, never a migration, following the exact precedent set by
 * `contact_links.type`. `starts_at`/`ends_at` are both nullable so an
 * announcement can be scheduled ahead of time or run indefinitely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('image_url', 2048);
            $table->string('link_url', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
