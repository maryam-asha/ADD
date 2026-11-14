<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects, their members, tasks, assignees and tags. People are plain user
 * ids with no foreign key, so the package does not depend on the host's
 * users table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('tone', 16);
            $table->unsignedBigInteger('lead_user_id')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pm_project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('pm_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
        });

        Schema::create('pm_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 48)->unique();
            $table->string('tone', 16);
            $table->timestamps();
        });

        Schema::create('pm_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('pm_projects')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('pm_tasks')->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 16)->default('todo');
            $table->string('priority', 16)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('due_on')->nullable();
            $table->decimal('estimate_hours', 6, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status', 'sort_order']);
        });

        Schema::create('pm_task_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('pm_tasks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');

            $table->unique(['task_id', 'user_id']);
        });

        Schema::create('pm_task_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('pm_tasks')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('pm_tags')->cascadeOnDelete();

            $table->unique(['task_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_task_tag');
        Schema::dropIfExists('pm_task_assignees');
        Schema::dropIfExists('pm_tasks');
        Schema::dropIfExists('pm_tags');
        Schema::dropIfExists('pm_project_members');
        Schema::dropIfExists('pm_projects');
    }
};
