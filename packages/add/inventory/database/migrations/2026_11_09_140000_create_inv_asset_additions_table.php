<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Capital additions: money spent on an asset that raises its cost,
        // depreciated over what is left of its life from the day added.
        Schema::create('inv_asset_additions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->date('added_on');
            $table->decimal('amount', 14, 2);
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            $table->json('attachments')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'added_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_asset_additions');
    }
};
