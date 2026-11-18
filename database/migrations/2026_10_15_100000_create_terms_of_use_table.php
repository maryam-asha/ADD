<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app's terms of use: one row, Markdown in each language
 * ({"en": "...", "ar": "..."}), as privacy_policies holds the privacy policy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_of_use', function (Blueprint $table) {
            $table->id();
            $table->json('content');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_of_use');
    }
};
