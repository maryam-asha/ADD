<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who does a checklist task (ChecklistAssignee), copied from its template
 * item like the title. Template items are JSON and carry `assignee` too;
 * items and tasks from before it are HR's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_checklist_tasks', function (Blueprint $table) {
            $table->string('assignee', 16)->default('hr')->after('kind');
            $table->index(['employee_id', 'assignee']);
        });
    }

    public function down(): void
    {
        Schema::table('hr_checklist_tasks', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'assignee']);
            $table->dropColumn('assignee');
        });
    }
};
