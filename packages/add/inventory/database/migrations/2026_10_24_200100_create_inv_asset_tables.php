<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One physical thing. Its location and custodian are the host's
        // (type + id pairs its resolvers know), never a foreign key.
        Schema::create('inv_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('inv_items')->restrictOnDelete();
            // Readable and sequential (AST-000042); the QR carries the
            // random token instead, so a label cannot be guessed.
            $table->string('code', 40)->nullable()->unique();
            $table->string('qr_token', 64)->unique();
            $table->string('serial_number', 100)->nullable()->index();
            $table->string('status', 20)->default('in_service')->index();
            $table->string('location_type', 40);
            $table->unsignedBigInteger('location_id');
            $table->date('purchased_on')->nullable();
            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('supplier')->nullable();
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->decimal('salvage_value', 14, 2)->nullable();
            $table->date('warranty_until')->nullable();
            // Set once its ending warranty has been announced.
            $table->timestamp('warranty_alerted_at')->nullable();
            $table->date('disposed_on')->nullable();
            $table->string('disposal_method', 20)->nullable();
            $table->decimal('disposal_proceeds', 14, 2)->nullable();
            $table->text('disposal_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['location_type', 'location_id']);
        });

        Schema::create('inv_asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            // Null on the row that registered the asset.
            $table->string('from_type', 40)->nullable();
            $table->unsignedBigInteger('from_id')->nullable();
            $table->string('to_type', 40);
            $table->unsignedBigInteger('to_id');
            $table->text('note')->nullable();
            // The host user who acted. No FK: the trail outlives the account.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestamp('moved_at');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('inv_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->string('custodian_type', 40);
            $table->unsignedBigInteger('custodian_id');
            $table->timestamp('assigned_at');
            $table->timestamp('returned_at')->nullable();
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->unsignedBigInteger('returned_by')->nullable();
            $table->text('note')->nullable();
            $table->text('return_note')->nullable();
            $table->timestamps();

            $table->index(['custodian_type', 'custodian_id']);
            $table->index(['asset_id', 'returned_at']);
        });

        Schema::create('inv_maintenance_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->string('title', 150);
            $table->unsignedSmallInteger('interval_months');
            $table->date('next_due_on')->index();
            // The due date last announced, so one is announced once.
            $table->date('alerted_for')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inv_maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('inv_assets')->cascadeOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('inv_maintenance_schedules')->nullOnDelete();
            $table->string('type', 20);
            $table->text('description');
            $table->string('vendor')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('opened_by')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_maintenance_records');
        Schema::dropIfExists('inv_maintenance_schedules');
        Schema::dropIfExists('inv_asset_assignments');
        Schema::dropIfExists('inv_asset_movements');
        Schema::dropIfExists('inv_assets');
    }
};
