<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app's Help & Support page text: one row, Markdown in each language
 * ({"en": "...", "ar": "..."}). The contact rows under it are contact_links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_support', function (Blueprint $table) {
            $table->id();
            $table->json('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_support');
    }
};
