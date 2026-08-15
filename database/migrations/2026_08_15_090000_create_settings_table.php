<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            // Global/0 sentinel, not null — MySQL allows repeated NULLs in a
            // unique index, which would let two "global" rows for the same
            // key coexist silently. See App\Domain\Settings\Models\Setting.
            $table->string('scope_type')->default('global');
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->string('type');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['key', 'scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
