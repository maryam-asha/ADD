<?php

use Add\Hr\Hr;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A host PersonStore keeps the person on its accounts, so the employee row
 * may hold no name. And when the host provides the work locations
 * (config hr.work_locations.model), assignments and job openings reference
 * its table instead of hr_work_locations — their current values pointed at
 * HR's own list and are cleared.
 */
return new class extends Migration
{
    private const LOCATED = ['hr_assignments', 'hr_job_openings'];

    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            $table->json('name')->nullable()->change();
        });

        $locations = Hr::workLocationsTable();

        if ($locations !== 'hr_work_locations') {
            $this->pointLocationsAt($locations);
        }
    }

    public function down(): void
    {
        if (Hr::workLocationsTable() !== 'hr_work_locations') {
            $this->pointLocationsAt('hr_work_locations');
        }
    }

    private function pointLocationsAt(string $locations): void
    {
        foreach (self::LOCATED as $located) {
            Schema::table($located, function (Blueprint $table) {
                $table->dropForeign(['work_location_id']);
            });

            DB::table($located)->whereNotNull('work_location_id')->update(['work_location_id' => null]);

            Schema::table($located, function (Blueprint $table) use ($locations) {
                $table->foreign('work_location_id')->references('id')->on($locations)->nullOnDelete();
            });
        }
    }
};
