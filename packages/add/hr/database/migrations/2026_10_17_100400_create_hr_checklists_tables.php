<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16)->index();
            $table->json('name');
            // [{title: {ar, en}, due_offset_days: int}, ...]
            $table->json('items');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_checklist_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('kind', 16);
            $table->foreignId('event_id')->nullable()->constrained('hr_employment_events')->nullOnDelete();
            // Copied from the template, so editing a template later never
            // rewrites tasks already given out.
            $table->json('title');
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['employee_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_checklist_tasks');
        Schema::dropIfExists('hr_checklist_templates');
    }
};
