<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My tasks" finds assignments by person, and a task's subtasks are found by
 * their parent. The unique key on the assignees starts with the task, so it
 * cannot serve the first, and the parent link was left without an index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_task_assignees', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('pm_tasks', function (Blueprint $table) {
            $table->dropIndex(['parent_id']);
        });

        Schema::table('pm_task_assignees', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
