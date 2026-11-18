<?php

namespace Tests\Feature\Forms;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAnswer;
use App\Domain\Forms\Models\FormField;
use App\Domain\Forms\Models\FormSubmission;
use App\Domain\Forms\Services\FormSubmissionService;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use App\Support\Forms\FieldType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The owner-agnostic half of the form engine: one set of tables serves
 * every model that owns a form (Event, ServiceType, ...) and every record
 * that holds the answers (EventAttendee, ServiceRequest, ...).
 */
class FormsDomainTest extends TestCase
{
    use RefreshDatabase;

    private function serviceType(): ServiceType
    {
        $axis = ServiceAxis::create([
            'slug' => 'workspace',
            'name' => ['en' => 'Workspace', 'ar' => 'مساحة'],
            'color' => '#123456',
            'order' => 0,
        ]);

        return ServiceType::create([
            'service_axis_id' => $axis->id,
            'slug' => 'maintenance',
            'name' => ['en' => 'Maintenance', 'ar' => 'صيانة'],
            'is_active' => true,
            'visibility' => 'both',
            'request_method' => 'form',
        ]);
    }

    private function recorder(): FormSubmissionService
    {
        return app(FormSubmissionService::class);
    }

    public function test_form_or_create_is_idempotent_per_purpose_and_stores_the_morph_alias(): void
    {
        $event = Event::factory()->create();

        $first = $event->formOrCreate(FormPurpose::Registration);
        $again = $event->formOrCreate(FormPurpose::Registration);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Form::count());
        $this->assertDatabaseHas('forms', [
            'formable_type' => 'event',
            'formable_id' => $event->id,
            'purpose' => 'registration',
        ]);
    }

    public function test_each_purpose_is_its_own_form(): void
    {
        $event = Event::factory()->create();

        $registration = $event->formOrCreate(FormPurpose::Registration);
        $request = $event->formOrCreate(FormPurpose::Request);

        $this->assertNotSame($registration->id, $request->id);
        $this->assertSame($registration->id, $event->form(FormPurpose::Registration)->first()->id);
        $this->assertSame($request->id, $event->form(FormPurpose::Request)->first()->id);
    }

    public function test_a_reserved_key_is_refused_even_without_a_request_layer(): void
    {
        $form = Event::factory()->create()->formOrCreate(FormPurpose::Registration);

        $this->expectException(RuntimeException::class);

        FormField::factory()->for($form)->create(['key' => 'email']);
    }

    public function test_a_legacy_reserved_key_row_stays_editable_while_its_key_is_untouched(): void
    {
        $form = Event::factory()->create()->formOrCreate(FormPurpose::Registration);
        $id = DB::table('form_fields')->insertGetId([
            'form_id' => $form->id,
            'key' => 'phone',
            'label' => json_encode(['en' => 'Phone', 'ar' => 'هاتف']),
            'type' => 'text',
            'is_required' => false,
            'is_active' => true,
            'order' => 0,
        ]);

        FormField::findOrFail($id)->update(['label' => ['en' => 'Mobile', 'ar' => 'جوال']]);

        $this->assertSame('Mobile', FormField::findOrFail($id)->label['en']);
    }

    public function test_an_events_registration_flag_follows_its_active_fields(): void
    {
        $event = Event::factory()->create(['has_registration_form' => false]);

        $field = FormField::factory()->forOwner($event, FormPurpose::Registration)->create();
        $this->assertTrue($event->fresh()->has_registration_form);

        $field->update(['is_active' => false]);
        $this->assertFalse($event->fresh()->has_registration_form);

        $field->update(['is_active' => true]);
        $field->delete();
        $this->assertFalse($event->fresh()->has_registration_form);
    }

    public function test_a_non_registration_form_leaves_the_events_flag_alone(): void
    {
        $event = Event::factory()->create(['has_registration_form' => false]);

        FormField::factory()->forOwner($event, FormPurpose::Request)->create();

        $this->assertFalse($event->fresh()->has_registration_form);
    }

    public function test_deleting_an_event_removes_its_form_and_everything_under_it(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create();
        $field = FormField::factory()->forOwner($event, FormPurpose::Registration)->ofType(FieldType::File)->create();
        $attendee = EventAttendee::factory()->create(['event_id' => $event->id]);
        $submission = $this->recorder()->record(
            $event->form(FormPurpose::Registration)->first(),
            $attendee,
            [$field->key => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')],
        );
        $path = $submission->answers()->first()->value_file;
        Storage::disk('public')->assertExists($path);

        $event->delete();

        $this->assertSame(0, Form::count());
        $this->assertSame(0, FormField::count());
        $this->assertSame(0, FormSubmission::count());
        $this->assertSame(0, FormAnswer::count());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_service_type_that_cannot_be_deleted_keeps_its_form(): void
    {
        $type = $this->serviceType();
        $type->formOrCreate(FormPurpose::Request);
        ServiceRequest::create([
            'service_type_id' => $type->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
        ]);

        try {
            $type->delete();
            $this->fail('Deleting a service type with requests should hit the restrict FK.');
        } catch (QueryException) {
            // expected
        }

        $this->assertSame(1, Form::count());
    }

    public function test_record_keeps_only_answered_active_fields_in_their_typed_columns(): void
    {
        $event = Event::factory()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'bio', 'label' => ['en' => 'Bio', 'ar' => 'نبذة']]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->select(['s', 'm', 'l'])->create(['key' => 'size']);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->checkbox(['a', 'b'])->create(['key' => 'tags']);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'blank']);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->inactive()->create(['key' => 'hidden']);
        $attendee = EventAttendee::factory()->create(['event_id' => $event->id]);

        $submission = $this->recorder()->record(
            $event->form(FormPurpose::Registration)->first(),
            $attendee,
            ['bio' => 'Hello', 'size' => 'm', 'tags' => ['a', 'b'], 'blank' => '', 'hidden' => 'x', 'unknown' => 'y'],
            $attendee->user,
        );

        $this->assertSame('event_attendee', $submission->subject_type);
        $this->assertSame($attendee->id, $submission->subject_id);
        $this->assertSame($attendee->user_id, $submission->user_id);

        $answers = $submission->answers()->get()->keyBy('field_key');
        $this->assertSame(['bio', 'size', 'tags'], $answers->keys()->sort()->values()->all());
        $this->assertSame('Hello', $answers['bio']->value_text);
        $this->assertSame('m', $answers['size']->value_json);
        $this->assertSame(['a', 'b'], $answers['tags']->value_json);
        $this->assertSame(FieldType::Text, $answers['bio']->field_type);
        $this->assertSame(['en' => 'Bio', 'ar' => 'نبذة'], $answers['bio']->field_label);
    }

    public function test_record_stores_an_upload_under_its_submission(): void
    {
        Storage::fake('public');
        $type = $this->serviceType();
        FormField::factory()->forOwner($type, FormPurpose::Request)->ofType(FieldType::File)->create(['key' => 'cv']);
        $request = ServiceRequest::create([
            'service_type_id' => $type->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
        ]);

        $submission = $this->recorder()->record(
            $type->form(FormPurpose::Request)->first(),
            $request,
            ['cv' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')],
        );

        $path = $submission->answers()->first()->value_file;
        $this->assertStringStartsWith("form-submissions/{$submission->id}/", $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_a_subject_reaches_its_answers_and_their_fields(): void
    {
        $event = Event::factory()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create(['key' => 'bio']);
        $attendee = EventAttendee::factory()->create(['event_id' => $event->id]);
        $other = EventAttendee::factory()->create(['event_id' => $event->id]);
        $form = $event->form(FormPurpose::Registration)->first();
        $this->recorder()->record($form, $attendee, ['bio' => 'Mine']);
        $this->recorder()->record($form, $other, ['bio' => 'Theirs']);

        $answers = EventAttendee::with('formAnswers.field')->findOrFail($attendee->id)->formAnswers;

        $this->assertCount(1, $answers);
        $this->assertSame('Mine', $answers->first()->resolvedValue());
        $this->assertSame('bio', $answers->first()->field->key);
    }

    public function test_deleting_a_field_keeps_its_answers_by_their_snapshot(): void
    {
        $event = Event::factory()->create();
        $field = FormField::factory()->forOwner($event, FormPurpose::Registration)
            ->create(['key' => 'bio', 'label' => ['en' => 'Bio', 'ar' => 'نبذة']]);
        $attendee = EventAttendee::factory()->create(['event_id' => $event->id]);
        $this->recorder()->record($event->form(FormPurpose::Registration)->first(), $attendee, ['bio' => 'Hello']);

        $field->delete();

        $answer = FormAnswer::firstOrFail();
        $this->assertNull($answer->form_field_id);
        $this->assertSame('bio', $answer->displayKey());
        $this->assertSame('Bio', $answer->displayLabel('en'));
        $this->assertSame('نبذة', $answer->displayLabel('ar'));
    }

    public function test_pruning_removes_orphaned_submissions_and_their_files_only(): void
    {
        Storage::fake('public');
        $event = Event::factory()->create();
        FormField::factory()->forOwner($event, FormPurpose::Registration)->ofType(FieldType::File)->create(['key' => 'cv']);
        $form = $event->form(FormPurpose::Registration)->first();
        $gone = EventAttendee::factory()->create(['event_id' => $event->id]);
        $kept = EventAttendee::factory()->create(['event_id' => $event->id]);
        $orphan = $this->recorder()->record($form, $gone, ['cv' => UploadedFile::fake()->create('a.pdf', 5, 'application/pdf')]);
        $live = $this->recorder()->record($form, $kept, ['cv' => UploadedFile::fake()->create('b.pdf', 5, 'application/pdf')]);
        $orphanPath = $orphan->answers()->first()->value_file;
        $livePath = $live->answers()->first()->value_file;

        // A database-level delete, as the users/events cascades do: no model
        // events fire, so nothing but the prune sweep can notice.
        DB::table('event_attendees')->where('id', $gone->id)->delete();

        $this->artisan('model:prune', ['--model' => [FormSubmission::class]])->assertSuccessful();

        $this->assertNull(FormSubmission::find($orphan->id));
        $this->assertNotNull(FormSubmission::find($live->id));
        Storage::disk('public')->assertMissing($orphanPath);
        Storage::disk('public')->assertExists($livePath);
    }

    public function test_pruning_removes_forms_whose_owner_is_gone(): void
    {
        $gone = Event::factory()->create();
        $kept = Event::factory()->create();
        $orphan = $gone->formOrCreate(FormPurpose::Registration);
        $live = $kept->formOrCreate(FormPurpose::Registration);

        DB::table('events')->where('id', $gone->id)->delete();

        $this->artisan('model:prune', ['--model' => [Form::class]])->assertSuccessful();

        $this->assertNull(Form::find($orphan->id));
        $this->assertNotNull(Form::find($live->id));
    }
}
