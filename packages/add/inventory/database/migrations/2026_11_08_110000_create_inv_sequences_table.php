<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Counters the package numbers things from (an item's code), moved
        // inside the transaction that uses the number, under a row lock — so
        // a save that rolls back gives its number back.
        Schema::create('inv_sequences', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_sequences');
    }
};
