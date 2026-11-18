<?php

namespace Tests\Feature\Forms;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Once the morph map aliases Event/EventAttendee/ServiceType/
 * ServiceRequest, spatie's performedOn() writes the alias to
 * `activity_log.subject_type`. This rewrites the rows written before, so
 * one subject is never stored two ways.
 */
class ActivityLogSubjectAliasMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_27_100005_alias_form_models_in_activity_log.php');
    }

    private function logRow(string $subjectType): int
    {
        return DB::table('activity_log')->insertGetId([
            'log_name' => 'default',
            'description' => 'approved',
            'subject_type' => $subjectType,
            'subject_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_class_names_become_aliases_and_back(): void
    {
        $attendee = $this->logRow('App\\Domain\\Ecosystem\\Models\\EventAttendee');
        $event = $this->logRow('App\\Domain\\Experience\\Models\\Event');
        $user = $this->logRow('App\\Domain\\Identity\\Models\\User');

        $this->migration()->up();

        $this->assertSame('event_attendee', DB::table('activity_log')->where('id', $attendee)->value('subject_type'));
        $this->assertSame('event', DB::table('activity_log')->where('id', $event)->value('subject_type'));
        $this->assertSame('App\\Domain\\Identity\\Models\\User', DB::table('activity_log')->where('id', $user)->value('subject_type'));

        $this->migration()->down();

        $this->assertSame('App\\Domain\\Ecosystem\\Models\\EventAttendee', DB::table('activity_log')->where('id', $attendee)->value('subject_type'));
        $this->assertSame('App\\Domain\\Experience\\Models\\Event', DB::table('activity_log')->where('id', $event)->value('subject_type'));
    }
}
