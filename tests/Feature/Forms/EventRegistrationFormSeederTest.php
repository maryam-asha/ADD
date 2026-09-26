<?php

namespace Tests\Feature\Forms;

use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use Database\Seeders\EventRegistrationFormSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DatabaseSeeder runs WithoutModelEvents, which silences the FormField hook
 * that normally keeps `has_registration_form` in step. The seeder has to
 * set the flag itself, and this runs it the same muted way to prove it.
 */
class EventRegistrationFormSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_events_are_flagged_by_their_forms_with_model_events_muted(): void
    {
        $asking = Event::factory()->create([
            'title' => ['en' => 'Figma for Non-Designers', 'ar' => 'فيجما'],
            'has_registration_form' => false,
        ]);
        $external = Event::factory()->create([
            'title' => ['en' => 'Fundraising in a Hard Market', 'ar' => 'تمويل'],
            'has_registration_form' => true,
        ]);

        Model::withoutEvents(fn () => $this->seed(EventRegistrationFormSeeder::class));

        $this->assertTrue($asking->registrationForm()->first()->activeFields()->exists());
        $this->assertTrue($asking->fresh()->has_registration_form);
        $this->assertNull($external->form(FormPurpose::Registration)->first());
        $this->assertFalse($external->fresh()->has_registration_form);
    }
}
