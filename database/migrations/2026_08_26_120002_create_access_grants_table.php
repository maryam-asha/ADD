<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lock_id')->constrained('devices')->restrictOnDelete();
            $table->string('grantee_type');
            $table->unsignedBigInteger('grantee_id');
            $table->string('source_type');
            $table->foreignId('source_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('allocation_model');
            $table->string('passcode_type');
            $table->text('passcode_value');
            $table->unsignedInteger('vendor_keyboard_pwd_id')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('must_activate_by');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('issued');
            $table->timestamps();

            $table->index(['grantee_type', 'grantee_id']);
            $table->index(['lock_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
