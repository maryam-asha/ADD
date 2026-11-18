<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('community_members', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            // marketing display only — no FK to users (confirmed v0.6)
            $table->enum('category', ['pioneers', 'growth_partners', 'investors', 'impact_partners']);
            $table->json('company')->nullable();
            $table->json('title')->nullable();
            $table->json('bio')->nullable();
            $table->json('long_bio')->nullable();
            $table->json('location')->nullable();
            $table->unsignedSmallInteger('year_joined')->nullable();
            $table->string('photo_url')->nullable();
            $table->json('social_links')->nullable();
            $table->json('skills')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('published')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('community_members');
    }
};
