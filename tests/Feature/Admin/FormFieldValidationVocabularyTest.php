<?php

namespace Tests\Feature\Admin;

use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormField;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
use App\Support\Forms\FieldType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin write path for the `validation` vocabulary.
 *
 * Every case runs against BOTH the Events and the Services endpoints: their
 * four Form Requests are near-duplicates that have already drifted in other
 * respects (different label lengths, different options handling), and the
 * whole point of ValidationVocabularyRule is that they cannot drift here.
 */
class FormFieldValidationVocabularyTest extends TestCase
{
    use RefreshDatabase;

    /** Counter for unique slugs across repeated calls in one test. */
    private int $made = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        // The admin group enforces abilities:dashboard on top of the role.
        Sanctum::actingAs($admin, ['*']);
    }

    /** @return array<string, array{0: string}> */
    public static function bothDomains(): array
    {
        return [
            'events' => ['events'],
            'services' => ['services'],
        ];
    }

    /** The collection URL for a freshly made owner in the given domain. */
    private function endpoint(string $domain): string
    {
        if ($domain === 'events') {
            $event = Event::factory()->create();

            return "/api/v1/admin/events/{$event->id}/registration-fields";
        }

        // Several cases build more than one owner per test, so the slugs
        // have to be unique rather than fixed.
        $n = ++$this->made;

        $axis = ServiceAxis::create([
            'slug' => "facilities-{$n}",
            'name' => ['en' => 'Facilities', 'ar' => 'المرافق'],
            'color' => '#334155',
        ]);

        $type = ServiceType::create([
            'service_axis_id' => $axis->id,
            'slug' => "cleaning-{$n}",
            'name' => ['en' => 'Cleaning', 'ar' => 'تنظيف'],
            'request_method' => 'form',
            'is_active' => true,
        ]);

        return "/api/v1/admin/service-types/{$type->id}/fields";
    }

    /** @param array<string, mixed> $overrides */
    private function postField(string $domain, array $overrides = []): TestResponse
    {
        return $this->postJson($this->endpoint($domain), array_merge([
            'key' => 'notes',
            'label' => ['en' => 'Notes', 'ar' => 'ملاحظات'],
            'type' => 'text',
        ], $overrides));
    }

    // ── Unknown and inappropriate keys ───────────────────────────────────

    #[DataProvider('bothDomains')]
    public function test_an_unknown_constraint_key_is_rejected(string $domain): void
    {
        $this->postField($domain, ['validation' => ['nonsense' => 1]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('validation');
    }

    #[DataProvider('bothDomains')]
    public function test_a_key_that_belongs_to_another_type_is_rejected(string $domain): void
    {
        // max_kb is a file setting; it used to be stored on a text field and
        // then silently ignored at submit time.
        $this->postField($domain, ['type' => 'text', 'validation' => ['max_kb' => 100]])
            ->assertStatus(422);

        $this->postField($domain, ['type' => 'text', 'validation' => ['min_date' => '2026-01-01']])
            ->assertStatus(422);

        // And the converse: character bounds are meaningless on a file.
        $this->postField($domain, ['type' => 'file', 'validation' => ['min' => 2]])
            ->assertStatus(422);
    }

    #[DataProvider('bothDomains')]
    public function test_a_select_accepts_no_bounds_beyond_its_options(string $domain): void
    {
        $this->postField($domain, [
            'type' => 'select',
            'options' => [['value' => 'a', 'label' => ['en' => 'A', 'ar' => 'أ']]],
            'validation' => ['min' => 1],
        ])->assertStatus(422);
    }

    // ── Shapes ───────────────────────────────────────────────────────────

    #[DataProvider('bothDomains')]
    public function test_a_character_bound_must_be_a_whole_number(string $domain): void
    {
        $this->postField($domain, ['validation' => ['max' => 'abc']])->assertStatus(422);
        $this->postField($domain, ['validation' => ['max' => 2.5]])->assertStatus(422);
        $this->postField($domain, ['validation' => ['max' => -1]])->assertStatus(422);
    }

    #[DataProvider('bothDomains')]
    public function test_a_number_field_accepts_a_fractional_bound(string $domain): void
    {
        // A value bound, unlike a character count, may legitimately have a
        // fractional part.
        $this->postField($domain, ['key' => 'weight', 'type' => 'number', 'validation' => ['max' => 2.5]])
            ->assertStatus(201);
    }

    #[DataProvider('bothDomains')]
    public function test_a_date_bound_must_be_an_absolute_calendar_date(string $domain): void
    {
        foreach (['tomorrow', '+30 days', '2026/01/01', '2026-02-30'] as $bad) {
            $this->postField($domain, ['key' => 'day', 'type' => 'date', 'validation' => ['min_date' => $bad]])
                ->assertStatus(422);
        }

        $this->postField($domain, ['key' => 'day', 'type' => 'date', 'validation' => ['min_date' => '2026-01-01']])
            ->assertStatus(201);
    }

    // ── Ordering ─────────────────────────────────────────────────────────

    #[DataProvider('bothDomains')]
    public function test_an_inverted_range_is_rejected(string $domain): void
    {
        $this->postField($domain, ['validation' => ['min' => 10, 'max' => 5]])
            ->assertStatus(422);

        $this->postField($domain, [
            'key' => 'day',
            'type' => 'date',
            'validation' => ['min_date' => '2026-12-31', 'max_date' => '2026-01-01'],
        ])->assertStatus(422);
    }

    #[DataProvider('bothDomains')]
    public function test_an_equal_range_is_allowed(string $domain): void
    {
        // Exactly four characters is a legitimate constraint.
        $this->postField($domain, ['validation' => ['min' => 4, 'max' => 4]])->assertStatus(201);
    }

    // ── Patterns ─────────────────────────────────────────────────────────

    #[DataProvider('bothDomains')]
    public function test_a_named_pattern_is_accepted(string $domain): void
    {
        $this->postField($domain, ['validation' => ['regex' => 'digits']])->assertStatus(201);
    }

    /**
     * The security-relevant case. A free-form expression could append rules
     * past Laravel's `|` split, or hang a worker with a nested quantifier.
     */
    #[DataProvider('bothDomains')]
    public function test_a_free_form_regex_is_refused(string $domain): void
    {
        foreach (['/^(a+)+$/', '/^a/|exists:users,email', '.*', ''] as $raw) {
            $this->postField($domain, ['validation' => ['regex' => $raw]])
                ->assertStatus(422);
        }
    }

    // ── Legacy and PATCH semantics ───────────────────────────────────────

    #[DataProvider('bothDomains')]
    public function test_the_shapes_already_in_production_are_still_accepted(string $domain): void
    {
        $this->postField($domain, ['type' => 'textarea', 'validation' => ['max' => 500]])->assertStatus(201);
        $this->postField($domain, [
            'key' => 'photo',
            'type' => 'file',
            'validation' => ['accept' => 'image/*', 'max_kb' => 5120],
        ])->assertStatus(201);
    }

    #[DataProvider('bothDomains')]
    public function test_an_empty_object_clears_the_constraints(string $domain): void
    {
        $this->postField($domain, ['validation' => []])->assertStatus(201);
    }

    /**
     * A PATCH that only changes a label carries no `type`, so the rule has to
     * resolve it from the stored row. Getting this wrong would either reject
     * every such PATCH or wave every key through.
     */
    public function test_a_patch_without_a_type_validates_against_the_stored_one(): void
    {
        $event = Event::factory()->create();
        $field = FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'notes',
            'type' => FieldType::Text,
        ]);

        $url = "/api/v1/admin/events/{$event->id}/registration-fields/{$field->id}";

        // max_kb is illegal on the stored text type, even unmentioned in the body.
        $this->patchJson($url, ['validation' => ['max_kb' => 10]])->assertStatus(422);

        // A text-appropriate key on the same PATCH is fine.
        $this->patchJson($url, ['validation' => ['max' => 50]])->assertStatus(200);
    }

    /** Omitting the key entirely must leave what is stored alone. */
    public function test_a_patch_omitting_validation_leaves_it_intact(): void
    {
        $event = Event::factory()->create();
        $field = FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'notes',
            'type' => FieldType::Text,
            'validation' => ['max' => 500],
        ]);

        $this->patchJson("/api/v1/admin/events/{$event->id}/registration-fields/{$field->id}", [
            'label' => ['en' => 'Renamed', 'ar' => 'مُعاد تسميته'],
        ])->assertStatus(200);

        $this->assertSame(['max' => 500], $field->fresh()->validation);
    }
}
