<?php

namespace Tests\Feature\Forms;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The one-off copy from the per-domain tables (service_type_fields,
 * service_request_values, event_registration_fields,
 * event_registration_field_values) into the unified form tables. Delete
 * this test with the release that drops the legacy tables.
 */
class LegacyFormBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function backfill(): Migration
    {
        return require database_path('migrations/2026_09_27_100004_backfill_forms_from_legacy_tables.php');
    }

    private function serviceType(string $slug): ServiceType
    {
        $axis = ServiceAxis::firstOrCreate(['slug' => 'workspace'], [
            'name' => ['en' => 'Workspace', 'ar' => 'مساحة'],
            'color' => '#123456',
            'order' => 0,
        ]);

        return ServiceType::create([
            'service_axis_id' => $axis->id,
            'slug' => $slug,
            'name' => ['en' => $slug, 'ar' => $slug],
            'is_active' => true,
            'visibility' => 'both',
            'request_method' => 'form',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function legacyField(string $table, string $ownerFk, int $ownerId, string $key, string $type, array $overrides = []): int
    {
        return DB::table($table)->insertGetId(array_merge([
            $ownerFk => $ownerId,
            'key' => $key,
            'label' => json_encode(['en' => ucfirst($key), 'ar' => 'سؤال '.$key], JSON_UNESCAPED_UNICODE),
            'placeholder' => null,
            'type' => $type,
            'options' => null,
            'validation' => null,
            'is_required' => false,
            'is_active' => true,
            'order' => 0,
            'created_at' => '2026-09-14 10:00:00',
            'updated_at' => '2026-09-14 11:00:00',
        ], $overrides));
    }

    public function test_it_copies_every_field_and_answer_into_the_unified_tables(): void
    {
        $withForm = $this->serviceType('maintenance');
        $this->serviceType('no-form');
        $issue = $this->legacyField('service_type_fields', 'service_type_id', $withForm->id, 'issue', 'select', [
            'options' => json_encode([['value' => 'wifi', 'label' => ['en' => 'Wi-Fi', 'ar' => 'واي فاي']]]),
            'validation' => json_encode(['required' => true]),
            'is_required' => true,
            'order' => 1,
        ]);
        $details = $this->legacyField('service_type_fields', 'service_type_id', $withForm->id, 'details', 'textarea', ['order' => 2]);
        $cv = $this->legacyField('service_type_fields', 'service_type_id', $withForm->id, 'cv', 'file', ['order' => 3]);
        $this->legacyField('service_type_fields', 'service_type_id', $withForm->id, 'old', 'text', ['is_active' => false, 'order' => 4]);

        $requester = User::factory()->create();
        $answered = ServiceRequest::create([
            'service_type_id' => $withForm->id,
            'user_id' => $requester->id,
            'status' => 'pending',
            'submitted_at' => '2026-09-20 08:30:00',
        ]);
        ServiceRequest::create(['service_type_id' => $withForm->id, 'user_id' => $requester->id, 'status' => 'pending']);
        DB::table('service_request_values')->insert([
            ['service_request_id' => $answered->id, 'service_type_field_id' => $issue, 'value_text' => null, 'value_json' => '"wifi"', 'value_file' => null, 'created_at' => '2026-09-20 08:30:00', 'updated_at' => '2026-09-20 08:30:00'],
            ['service_request_id' => $answered->id, 'service_type_field_id' => $details, 'value_text' => 'It drops every hour', 'value_json' => null, 'value_file' => null, 'created_at' => '2026-09-20 08:30:00', 'updated_at' => '2026-09-20 08:30:00'],
            ['service_request_id' => $answered->id, 'service_type_field_id' => $cv, 'value_text' => null, 'value_json' => null, 'value_file' => "service-requests/{$answered->id}/cv.pdf", 'created_at' => '2026-09-20 08:30:00', 'updated_at' => '2026-09-20 08:30:00'],
        ]);

        $event = Event::factory()->create(['has_registration_form' => true]);
        $diet = $this->legacyField('event_registration_fields', 'event_id', $event->id, 'diet', 'checkbox');
        $rating = $this->legacyField('event_registration_fields', 'event_id', $event->id, 'rating', 'rating', ['order' => 1]);
        $attendee = EventAttendee::factory()->create(['event_id' => $event->id]);
        DB::table('event_attendees')->where('id', $attendee->id)->update(['created_at' => '2026-09-18 12:00:00']);
        DB::table('event_registration_field_values')->insert([
            ['event_attendee_id' => $attendee->id, 'event_registration_field_id' => $diet, 'value_text' => null, 'value_json' => '["veg","halal"]', 'value_file' => null, 'created_at' => '2026-09-18 12:00:00', 'updated_at' => '2026-09-18 12:00:00'],
            ['event_attendee_id' => $attendee->id, 'event_registration_field_id' => $rating, 'value_text' => null, 'value_json' => '4', 'value_file' => null, 'created_at' => '2026-09-18 12:00:00', 'updated_at' => '2026-09-18 12:00:00'],
        ]);

        $this->backfill()->up();

        // One form per owner that had fields, none for the owner without.
        $this->assertSame(2, DB::table('forms')->count());
        $serviceForm = DB::table('forms')->where(['formable_type' => 'service_type', 'formable_id' => $withForm->id, 'purpose' => 'request'])->first();
        $eventForm = DB::table('forms')->where(['formable_type' => 'event', 'formable_id' => $event->id, 'purpose' => 'registration'])->first();
        $this->assertNotNull($serviceForm);
        $this->assertNotNull($eventForm);

        // Every field, inactive ones included, with its columns intact.
        $this->assertSame(6, DB::table('form_fields')->count());
        $newIssue = DB::table('form_fields')->where(['form_id' => $serviceForm->id, 'key' => 'issue'])->first();
        $legacyIssue = DB::table('service_type_fields')->find($issue);
        foreach (['label', 'placeholder', 'type', 'options', 'validation', 'is_required', 'is_active', 'order', 'created_at', 'updated_at'] as $column) {
            $this->assertEquals($legacyIssue->{$column}, $newIssue->{$column}, "form_fields.{$column}");
        }
        $this->assertFalse((bool) DB::table('form_fields')->where(['form_id' => $serviceForm->id, 'key' => 'old'])->value('is_active'));

        // A submission only where there were answers, carrying its header's facts.
        $this->assertSame(2, DB::table('form_submissions')->count());
        $serviceSubmission = DB::table('form_submissions')->where(['subject_type' => 'service_request', 'subject_id' => $answered->id])->first();
        $this->assertSame($serviceForm->id, (int) $serviceSubmission->form_id);
        $this->assertSame($requester->id, (int) $serviceSubmission->user_id);
        $this->assertSame('2026-09-20 08:30:00', (string) $serviceSubmission->submitted_at);
        $eventSubmission = DB::table('form_submissions')->where(['subject_type' => 'event_attendee', 'subject_id' => $attendee->id])->first();
        $this->assertSame($eventForm->id, (int) $eventSubmission->form_id);
        $this->assertSame($attendee->user_id, (int) $eventSubmission->user_id);
        $this->assertSame('2026-09-18 12:00:00', (string) $eventSubmission->submitted_at);

        // Answers: mapped to the new field, snapshot taken, values byte for byte.
        $this->assertSame(5, DB::table('form_answers')->count());
        $issueAnswer = DB::table('form_answers')->where('form_field_id', $newIssue->id)->first();
        $this->assertSame((int) $serviceSubmission->id, (int) $issueAnswer->form_submission_id);
        $this->assertSame('issue', $issueAnswer->field_key);
        $this->assertSame('select', $issueAnswer->field_type);
        $this->assertSame($legacyIssue->label, $issueAnswer->field_label);
        $this->assertSame('"wifi"', $issueAnswer->value_json);
        $this->assertSame('It drops every hour', DB::table('form_answers')->where('field_key', 'details')->value('value_text'));
        $this->assertSame("service-requests/{$answered->id}/cv.pdf", DB::table('form_answers')->where('field_key', 'cv')->value('value_file'));
        $this->assertSame('["veg","halal"]', DB::table('form_answers')->where('field_key', 'diet')->value('value_json'));
        $this->assertSame('4', DB::table('form_answers')->where('field_key', 'rating')->value('value_json'));

        // The stored flag is left exactly as it was.
        $this->assertTrue((bool) DB::table('events')->where('id', $event->id)->value('has_registration_form'));
    }

    public function test_an_answer_whose_field_belongs_to_another_owner_aborts_the_whole_copy(): void
    {
        $mine = $this->serviceType('mine');
        $theirs = $this->serviceType('theirs');
        $this->legacyField('service_type_fields', 'service_type_id', $mine->id, 'details', 'text');
        $foreign = $this->legacyField('service_type_fields', 'service_type_id', $theirs->id, 'secret', 'text');
        $request = ServiceRequest::create(['service_type_id' => $mine->id, 'user_id' => User::factory()->create()->id, 'status' => 'pending']);
        DB::table('service_request_values')->insert([
            'service_request_id' => $request->id,
            'service_type_field_id' => $foreign,
            'value_text' => 'misfiled',
        ]);

        try {
            $this->backfill()->up();
            $this->fail('A value filed against another owner\'s field must stop the backfill.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, DB::table('forms')->count());
        $this->assertSame(0, DB::table('form_fields')->count());
    }

    public function test_a_field_type_the_engine_does_not_know_aborts_the_whole_copy(): void
    {
        $type = $this->serviceType('odd');
        $this->legacyField('service_type_fields', 'service_type_id', $type->id, 'when', 'datetime');

        $this->expectException(RuntimeException::class);

        $this->backfill()->up();
    }

    public function test_down_empties_the_unified_tables_and_leaves_the_legacy_ones(): void
    {
        $type = $this->serviceType('maintenance');
        $this->legacyField('service_type_fields', 'service_type_id', $type->id, 'details', 'text');
        $migration = $this->backfill();
        $migration->up();

        $migration->down();

        $this->assertSame(0, DB::table('forms')->count());
        $this->assertSame(0, DB::table('form_fields')->count());
        $this->assertSame(1, DB::table('service_type_fields')->count());
    }
}
