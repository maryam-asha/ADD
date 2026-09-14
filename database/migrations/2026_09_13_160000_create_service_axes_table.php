<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_axes', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();   // core | experience | ecosystem
            $table->json('name');               // {en, ar}
            $table->string('color', 10);        // #hex — UI only
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_axes');
    }
};
