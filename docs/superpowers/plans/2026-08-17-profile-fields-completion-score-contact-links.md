# Profile Fields, Completion Score, Contact Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add four optional profile fields (gender, instagram_url, behance_url, website_url), a derived profile-completion score exposed on the member profile endpoint, and a new admin-managed/publicly-readable `contact_links` entity.

**Architecture:** Extend the two existing per-user profile tables (`user_personal_profiles`, `user_professional_profiles`) with the new columns, following their exact existing field/resource/request wiring. Add a stateless `ProfileCompletionService` (Identity domain) that computes the score and missing-fields list on read — no new column, no cache — and wire it into the existing `GET /member/profile` response via `JsonResource::additional()`. Add `ContactLink` as a new Ecosystem-domain resource using the codebase's established two-tier `AdminResourceController`/`PublicResourceController` pattern, identical in shape to `Founder`/`Partner`. No ADD Member tier/promotion mechanism or notification code is built — investigation confirmed neither exists yet in this codebase, so that part of the spec is satisfied by reporting the gap (decision doc) and a guard test, not by building anything.

**Tech Stack:** Laravel 12, PHPUnit (`php artisan test`), SQLite in-memory test DB, Laravel Pint.

**Spec:** The Phase 5 spec pasted into this session (Profile Fields, Completion Score, Contact Links — decisions #11, #12, #15 from the 2026-08-15 decision session). No separate spec file exists on disk; this plan's Global Constraints section reproduces every constraint from it verbatim.

## Global Constraints

- All four new fields (`gender`, `instagram_url`, `behance_url`, `website_url`) are optional; none is ever required for account use.
- Completion weights are fixed in a PHP config/class, never database-editable — no admin UI for adjusting them.
- Weight table (sums to 100): baseline (name + verified phone) 25 · avatar 15 · job title 10 · bio 10 · city 10 · industry 7 · company name 6 · LinkedIn 5 · Instagram 4 · Behance 3 · gender 3 · website 2.
- A freshly registered member's score is exactly 25, not 0.
- The completion score must be a derived value (compute-on-read or cache-with-correct-invalidation), never a column that can silently drift out of sync.
- `profile.completion_threshold` must be read via `SettingService`, never hardcoded.
- **Do not build an ADD Member promotion/tier mechanism.** Investigation (recorded in the decision doc for this phase) confirmed none exists yet anywhere in this codebase — per the spec's own instruction, this phase stops and reports that gap instead of inventing one.
- `contact_links.type` must support adding a new platform via a row insert alone — never a code change or migration.
- Out of scope entirely: guest visits, member/company groups, anything touching the `Booking` domain.
- Every migration uses a `string` column + PHP backed enum cast where a fixed set is needed — never a MySQL `->enum(...)` column (enforced by `tests/Guards/NoNewMysqlEnumColumnsTest.php`; new enum-cast columns must also be registered in `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`).
- Update endpoints (`PATCH`/`PUT`) return `{"message": "..."}` via a `lang/{en,ar}/api.php` key, never the updated resource; `store()` is the one exception and returns the created resource.

---

## Task 1: Add `gender`, `instagram_url`, `behance_url`, `website_url` to the member profile

**Files:**
- Create: `database/migrations/2026_08_17_090000_add_gender_to_user_personal_profiles_table.php`
- Create: `database/migrations/2026_08_17_090001_add_social_links_to_user_professional_profiles_table.php`
- Create: `app/Domain/Identity/Enums/Gender.php`
- Modify: `app/Domain/Identity/Models/UserPersonalProfile.php`
- Modify: `app/Domain/Identity/Models/UserProfessionalProfile.php`
- Modify: `app/Http/Resources/UserPersonalProfileResource.php`
- Modify: `app/Http/Resources/UserProfessionalProfileResource.php`
- Modify: `app/Http/Requests/Member/UpdateProfileRequest.php`
- Modify: `app/Http/Controllers/Api/V1/Member/ProfileController.php`
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Feature/Member/ProfileControllerTest.php`

**Interfaces:**
- Produces: `App\Domain\Identity\Enums\Gender` (`Male = 'male'`, `Female = 'female'`) — consumed by `UserPersonalProfile::casts()`, `UpdateProfileRequest::rules()`, and Task 2's `ProfileCompletionService`.
- Produces: `UserPersonalProfile::$fillable` gains `gender`; `UserProfessionalProfile::$fillable` gains `instagram_url`, `behance_url`, `website_url`. Task 2 reads these via `$user->personalProfile`/`$user->professionalProfile`.

- [ ] **Step 1: Write the failing feature tests**

Append these three methods to the end of the `ProfileControllerTest` class (before the final closing `}`), in `tests/Feature/Member/ProfileControllerTest.php`:

```php
    public function test_show_includes_gender_and_the_new_social_links_as_null_by_default(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('data.personal.gender', null);
        $response->assertJsonPath('data.professional.instagram_url', null);
        $response->assertJsonPath('data.professional.behance_url', null);
        $response->assertJsonPath('data.professional.website_url', null);
    }

    public function test_patch_can_set_gender_and_the_new_social_links(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('lang', 'en')->patchJson('/api/v1/member/profile', [
            'gender' => 'female',
            'instagram_url' => 'https://instagram.com/example',
            'behance_url' => 'https://behance.net/example',
            'website_url' => 'https://example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Profile updated.');

        $this->assertDatabaseHas('user_personal_profiles', [
            'user_id' => $member->id,
            'gender' => 'female',
        ]);
        $this->assertDatabaseHas('user_professional_profiles', [
            'user_id' => $member->id,
            'instagram_url' => 'https://instagram.com/example',
            'behance_url' => 'https://behance.net/example',
            'website_url' => 'https://example.com',
        ]);

        $getResponse = $this->getJson('/api/v1/member/profile');
        $getResponse->assertJsonPath('data.personal.gender', 'female');
    }

    public function test_patch_rejects_an_invalid_gender_value(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/profile', [
            'gender' => 'not-a-real-value',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['gender']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Member/ProfileControllerTest.php`
Expected: FAIL — `gender` is not a valid key on `data.personal` (undefined array key / null-not-matched), `instagram_url`/`behance_url`/`website_url` similarly absent, and the PATCH calls either silently drop the fields (no validation rule yet) or error.

- [ ] **Step 3: Create the `Gender` enum**

Create `app/Domain/Identity/Enums/Gender.php`:

```php
<?php

namespace App\Domain\Identity\Enums;

/**
 * Optional, self-reported. Two cases chosen as a reasonable minimal
 * default — the source spec left the value set unspecified, flagged in
 * docs/decisions/profile-fields-completion-score-contact-links.md.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
}
```

- [ ] **Step 4: Write the migrations**

Create `database/migrations/2026_08_17_090000_add_gender_to_user_personal_profiles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_personal_profiles', function (Blueprint $table) {
            $table->string('gender')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('user_personal_profiles', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
```

Create `database/migrations/2026_08_17_090001_add_social_links_to_user_professional_profiles_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_professional_profiles', function (Blueprint $table) {
            $table->string('instagram_url')->nullable()->after('linkedin_url');
            $table->string('behance_url')->nullable()->after('instagram_url');
            $table->string('website_url')->nullable()->after('behance_url');
        });
    }

    public function down(): void
    {
        Schema::table('user_professional_profiles', function (Blueprint $table) {
            $table->dropColumn(['instagram_url', 'behance_url', 'website_url']);
        });
    }
};
```

- [ ] **Step 5: Update the models**

Replace the full contents of `app/Domain/Identity/Models/UserPersonalProfile.php`:

```php
<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\Gender;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPersonalProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bio',
        'city',
        'avatar_url',
        'gender',
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

In `app/Domain/Identity/Models/UserProfessionalProfile.php`, change the `$fillable` array:

```php
    protected $fillable = [
        'user_id',
        'job_title',
        'company_name',
        'industry',
        'linkedin_url',
        'instagram_url',
        'behance_url',
        'website_url',
    ];
```

- [ ] **Step 6: Update the resources**

Replace the `toArray` body in `app/Http/Resources/UserPersonalProfileResource.php`:

```php
    public function toArray(Request $request): array
    {
        return [
            'bio' => $this->bio,
            'city' => $this->city,
            'avatar_url' => $this->avatar_url,
            'gender' => $this->gender?->value,
        ];
    }
```

Replace the `toArray` body in `app/Http/Resources/UserProfessionalProfileResource.php`:

```php
    public function toArray(Request $request): array
    {
        return [
            'job_title' => $this->job_title,
            'company_name' => $this->company_name,
            'industry' => $this->industry,
            'linkedin_url' => $this->linkedin_url,
            'instagram_url' => $this->instagram_url,
            'behance_url' => $this->behance_url,
            'website_url' => $this->website_url,
        ];
    }
```

- [ ] **Step 7: Update the validation rules**

Replace the full contents of `app/Http/Requests/Member/UpdateProfileRequest.php`:

```php
<?php

namespace App\Http\Requests\Member;

use App\Domain\Identity\Enums\Gender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bio' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:120'],
            'avatar_url' => ['nullable', 'url'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'job_title' => ['nullable', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:120'],
            'linkedin_url' => ['nullable', 'url'],
            'instagram_url' => ['nullable', 'url'],
            'behance_url' => ['nullable', 'url'],
            'website_url' => ['nullable', 'url'],
        ];
    }
}
```

- [ ] **Step 8: Update the controller's field lists**

In `app/Http/Controllers/Api/V1/Member/ProfileController.php`, change the two field arrays inside `update()`:

```php
        $personalFields = ['bio', 'city', 'avatar_url', 'gender'];
        $professionalFields = ['job_title', 'company_name', 'industry', 'linkedin_url', 'instagram_url', 'behance_url', 'website_url'];
```

- [ ] **Step 9: Register the new enum-cast column with the guard test**

In `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`, add the import:

```php
use App\Domain\Identity\Enums\Gender;
```

next to the existing `use App\Domain\Identity\Enums\ErrorLogPlatform;` line, and add the model import:

```php
use App\Domain\Identity\Models\UserPersonalProfile;
```

next to the existing `use App\Domain\Identity\Models\ErrorLog;` line. Then add this entry to the `EXPECTED_CASTS` array (next to the existing `ErrorLog::class => [...]` entry):

```php
        UserPersonalProfile::class => [
            'gender' => Gender::class,
        ],
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Member/ProfileControllerTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Guards/NoNewMysqlEnumColumnsTest.php tests/Unit/Domain/Identity/UserProfileRelationsTest.php`
Expected: PASS (all green).

- [ ] **Step 11: Commit**

```bash
git add app/Domain/Identity/Enums/Gender.php app/Domain/Identity/Models/UserPersonalProfile.php app/Domain/Identity/Models/UserProfessionalProfile.php app/Http/Resources/UserPersonalProfileResource.php app/Http/Resources/UserProfessionalProfileResource.php app/Http/Requests/Member/UpdateProfileRequest.php app/Http/Controllers/Api/V1/Member/ProfileController.php database/migrations/2026_08_17_090000_add_gender_to_user_personal_profiles_table.php database/migrations/2026_08_17_090001_add_social_links_to_user_professional_profiles_table.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Feature/Member/ProfileControllerTest.php
git commit -m "feat(identity): add gender and social link fields to the member profile"
```

---

## Task 2: `ProfileCompletionService`

**Files:**
- Create: `app/Domain/Identity/Services/ProfileCompletionService.php`
- Test: `tests/Unit/Domain/Identity/ProfileCompletionServiceTest.php`

**Interfaces:**
- Consumes: `App\Domain\Identity\Models\User` (via `$user->personalProfile`/`$user->professionalProfile`, both already `HasOne` relations on `User`), and `UserPersonalProfile`/`UserProfessionalProfile` columns from Task 1.
- Produces: `ProfileCompletionService::score(User $user): int` and `ProfileCompletionService::missingFields(User $user): array` (a `list<string>` of field keys) — consumed by Task 3's `ProfileController::show()`.

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/Domain/Identity/ProfileCompletionServiceTest.php`:

```php
<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Domain\Identity\Services\ProfileCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const WEIGHTS = [
        'avatar_url' => 15,
        'job_title' => 10,
        'bio' => 10,
        'city' => 10,
        'industry' => 7,
        'company_name' => 6,
        'linkedin_url' => 5,
        'instagram_url' => 4,
        'behance_url' => 3,
        'gender' => 3,
        'website_url' => 2,
    ];

    public function test_a_freshly_registered_member_scores_exactly_25(): void
    {
        $user = User::factory()->create();

        $service = new ProfileCompletionService;

        $this->assertSame(25, $service->score($user));
        $this->assertCount(11, $service->missingFields($user));
    }

    public function test_each_personal_field_contributes_its_own_weight_in_isolation(): void
    {
        $service = new ProfileCompletionService;

        foreach ([
            'avatar_url' => ['avatar_url' => 'https://example.com/a.png'],
            'bio' => ['bio' => 'Hello'],
            'city' => ['city' => 'Aleppo'],
            'gender' => ['gender' => 'male'],
        ] as $field => $attributes) {
            $user = User::factory()->create();
            UserPersonalProfile::create(['user_id' => $user->id] + $attributes);

            $this->assertSame(
                25 + self::WEIGHTS[$field],
                $service->score($user),
                "Field [{$field}] did not contribute its documented weight in isolation."
            );
        }
    }

    public function test_each_professional_field_contributes_its_own_weight_in_isolation(): void
    {
        $service = new ProfileCompletionService;

        foreach ([
            'job_title' => ['job_title' => 'Engineer'],
            'company_name' => ['company_name' => 'ACME'],
            'industry' => ['industry' => 'Software'],
            'linkedin_url' => ['linkedin_url' => 'https://linkedin.com/in/x'],
            'instagram_url' => ['instagram_url' => 'https://instagram.com/x'],
            'behance_url' => ['behance_url' => 'https://behance.net/x'],
            'website_url' => ['website_url' => 'https://example.com'],
        ] as $field => $attributes) {
            $user = User::factory()->create();
            UserProfessionalProfile::create(['user_id' => $user->id] + $attributes);

            $this->assertSame(
                25 + self::WEIGHTS[$field],
                $service->score($user),
                "Field [{$field}] did not contribute its documented weight in isolation."
            );
        }
    }

    public function test_filling_every_field_scores_exactly_100_with_no_fields_missing(): void
    {
        $user = User::factory()->create();
        UserPersonalProfile::create([
            'user_id' => $user->id,
            'bio' => 'Hello',
            'city' => 'Aleppo',
            'avatar_url' => 'https://example.com/a.png',
            'gender' => 'male',
        ]);
        UserProfessionalProfile::create([
            'user_id' => $user->id,
            'job_title' => 'Engineer',
            'company_name' => 'ACME',
            'industry' => 'Software',
            'linkedin_url' => 'https://linkedin.com/in/x',
            'instagram_url' => 'https://instagram.com/x',
            'behance_url' => 'https://behance.net/x',
            'website_url' => 'https://example.com',
        ]);

        $service = new ProfileCompletionService;

        $this->assertSame(100, $service->score($user));
        $this->assertSame([], $service->missingFields($user));
    }

    public function test_a_partial_profile_reports_exactly_the_fields_still_missing(): void
    {
        $user = User::factory()->create();
        UserPersonalProfile::create([
            'user_id' => $user->id,
            'bio' => 'Hello',
            'city' => 'Aleppo',
        ]);

        $service = new ProfileCompletionService;

        $this->assertSame(
            ['avatar_url', 'job_title', 'industry', 'company_name', 'linkedin_url', 'instagram_url', 'behance_url', 'gender', 'website_url'],
            $service->missingFields($user)
        );
    }

    public function test_score_is_computed_on_read_and_reflects_a_field_change_immediately(): void
    {
        $user = User::factory()->create();
        $profile = UserPersonalProfile::create(['user_id' => $user->id]);

        $service = new ProfileCompletionService;
        $this->assertSame(25, $service->score($user->fresh()));

        $profile->update(['bio' => 'Hello']);

        $this->assertSame(35, $service->score($user->fresh()));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/Domain/Identity/ProfileCompletionServiceTest.php`
Expected: FAIL with "Class App\Domain\Identity\Services\ProfileCompletionService not found".

- [ ] **Step 3: Write the service**

Create `app/Domain/Identity/Services/ProfileCompletionService.php`:

```php
<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;

/**
 * Weights are fixed here, not database-editable (2026-08-17 decision
 * session) — see
 * docs/decisions/profile-fields-completion-score-contact-links.md. Baseline
 * (name + verified phone, always true by the time a User row exists — see
 * decision doc) is a flat 25; the remaining 75 is earned per filled field,
 * computed fresh on every call rather than cached, so it can never drift
 * out of sync with the profile rows it reads.
 */
class ProfileCompletionService
{
    private const BASELINE_SCORE = 25;

    /** @var array<string, int> field key => weight, summing to 75 */
    private const WEIGHTS = [
        'avatar_url' => 15,
        'job_title' => 10,
        'bio' => 10,
        'city' => 10,
        'industry' => 7,
        'company_name' => 6,
        'linkedin_url' => 5,
        'instagram_url' => 4,
        'behance_url' => 3,
        'gender' => 3,
        'website_url' => 2,
    ];

    /** @var list<string> fields that live on UserPersonalProfile rather than UserProfessionalProfile */
    private const PERSONAL_FIELDS = ['avatar_url', 'bio', 'city', 'gender'];

    public function score(User $user): int
    {
        $earned = 0;

        foreach (self::WEIGHTS as $field => $weight) {
            if ($this->fieldValue($user, $field) !== null) {
                $earned += $weight;
            }
        }

        return self::BASELINE_SCORE + $earned;
    }

    /**
     * @return list<string>
     */
    public function missingFields(User $user): array
    {
        $missing = [];

        foreach (array_keys(self::WEIGHTS) as $field) {
            if ($this->fieldValue($user, $field) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function fieldValue(User $user, string $field): mixed
    {
        if (in_array($field, self::PERSONAL_FIELDS, true)) {
            return $user->personalProfile?->{$field};
        }

        return $user->professionalProfile?->{$field};
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/Domain/Identity/ProfileCompletionServiceTest.php`
Expected: PASS (all 6 tests green).

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Identity/Services/ProfileCompletionService.php tests/Unit/Domain/Identity/ProfileCompletionServiceTest.php
git commit -m "feat(identity): add ProfileCompletionService"
```

---

## Task 3: Expose the completion score on `GET /member/profile`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Member/ProfileController.php`
- Test: Create `tests/Feature/Member/ProfileCompletionTest.php`

**Interfaces:**
- Consumes: `ProfileCompletionService::score()`/`missingFields()` (Task 2), `App\Domain\Settings\Services\SettingService::get()` (existing), the already-seeded `profile.completion_threshold` setting key (default `80`, seeded in `database/seeders/SettingSeeder.php` — no seeder change needed here).
- Produces: `GET /api/v1/member/profile` response gains a top-level `completion` key (sibling of `data`, via `JsonResource::additional()`): `{"score": int, "threshold": int, "missing_fields": list<string>}`.

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/Member/ProfileCompletionTest.php`:

```php
<?php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
    }

    public function test_get_profile_reports_score_25_and_the_seeded_threshold_for_a_fresh_member(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('completion.score', 25);
        $response->assertJsonPath('completion.threshold', 80);
        $this->assertContains('avatar_url', $response->json('completion.missing_fields'));
    }

    public function test_get_profile_score_rises_after_the_member_fills_in_fields(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/profile', [
            'bio' => 'Coffee and code.',
            'avatar_url' => 'https://example.com/avatar.png',
        ])->assertOk();

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('completion.score', 25 + 10 + 15);
        $this->assertNotContains('bio', $response->json('completion.missing_fields'));
        $this->assertNotContains('avatar_url', $response->json('completion.missing_fields'));
    }

    public function test_changing_the_threshold_setting_changes_the_reported_threshold_without_a_code_change(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        app(SettingService::class)->set('profile.completion_threshold', 50, SettingValueType::Int);

        $response = $this->getJson('/api/v1/member/profile');

        $response->assertOk();
        $response->assertJsonPath('completion.threshold', 50);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/Member/ProfileCompletionTest.php`
Expected: FAIL — the response has no top-level `completion` key.

- [ ] **Step 3: Wire the service into the controller**

Replace the full contents of `app/Http/Controllers/Api/V1/Member/ProfileController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Domain\Identity\Services\ProfileCompletionService;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateProfileRequest;
use App\Http\Resources\MemberProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    public function show(Request $request, ProfileCompletionService $completion, SettingService $settings): MemberProfileResource
    {
        $user = $request->user();

        return (new MemberProfileResource($user))->additional([
            'completion' => [
                'score' => $completion->score($user),
                'threshold' => $settings->get('profile.completion_threshold', 80),
                'missing_fields' => $completion->missingFields($user),
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $personalFields = ['bio', 'city', 'avatar_url', 'gender'];
        $professionalFields = ['job_title', 'company_name', 'industry', 'linkedin_url', 'instagram_url', 'behance_url', 'website_url'];

        $validated = $request->validated();
        $user = $request->user();

        DB::transaction(function () use ($user, $validated, $personalFields, $professionalFields) {
            UserPersonalProfile::updateOrCreate(
                ['user_id' => $user->id],
                array_intersect_key($validated, array_flip($personalFields))
            );

            UserProfessionalProfile::updateOrCreate(
                ['user_id' => $user->id],
                array_intersect_key($validated, array_flip($professionalFields))
            );
        });

        return response()->json(['message' => __('api.member.profile_updated')]);
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/Member/ProfileCompletionTest.php tests/Feature/Member/ProfileControllerTest.php`
Expected: PASS (all green).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/Member/ProfileController.php tests/Feature/Member/ProfileCompletionTest.php
git commit -m "feat(identity): expose profile completion score on GET /member/profile"
```

---

## Task 4: `ContactLink` schema, model, and admin CRUD

**Files:**
- Create: `database/migrations/2026_08_17_090002_create_contact_links_table.php`
- Create: `app/Domain/Ecosystem/Models/ContactLink.php`
- Create: `app/Http/Resources/ContactLinkResource.php`
- Create: `app/Http/Requests/Admin/StoreContactLinkRequest.php`
- Create: `app/Http/Requests/Admin/UpdateContactLinkRequest.php`
- Create: `app/Http/Controllers/Api/V1/Admin/ContactLinkController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`
- Modify: `lang/ar/api.php`
- Test: Create `tests/Feature/Ecosystem/ContactLinkAdminTest.php`

**Interfaces:**
- Produces: `App\Domain\Ecosystem\Models\ContactLink` (`type: string`, `value: string`, `label: ?string`, `sort_order: int`, `is_visible: bool`) — consumed by Task 5's `Public\ContactLinkController`.
- Produces: `App\Http\Resources\ContactLinkResource` — reused as-is by Task 5's public controller (same reuse pattern as `FounderResource`/`PartnerResource`).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Ecosystem/ContactLinkAdminTest.php`:

```php
<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactLinkAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_a_contact_link_with_real_defaults(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/contact-links', [
            'type' => 'social_instagram',
            'value' => 'https://instagram.com/adddistrict',
            'label' => 'Instagram',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $response->assertJsonPath('data.is_visible', true);
        $this->assertDatabaseHas('contact_links', [
            'type' => 'social_instagram',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
    }

    public function test_a_new_platform_type_is_accepted_without_any_allow_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/contact-links', [
            'type' => 'social_threads',
            'value' => '@add_district',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'social_threads');
    }

    public function test_operations_can_list_admin_can_update_and_delete(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $link = ContactLink::create([
            'type' => 'website',
            'value' => 'https://example.com',
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        $this->getJson('/api/v1/admin/contact-links')
            ->assertOk()
            ->assertJsonPath('data.0.id', $link->id);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('lang', 'en')
            ->putJson("/api/v1/admin/contact-links/{$link->id}", [
                'type' => 'website',
                'value' => 'https://newsite.example.com',
                'is_visible' => false,
            ])
            ->assertOk()
            ->assertExactJson(['message' => 'Contact link updated.']);

        $this->assertDatabaseHas('contact_links', [
            'id' => $link->id,
            'value' => 'https://newsite.example.com',
            'is_visible' => 0,
        ]);

        $this->deleteJson("/api/v1/admin/contact-links/{$link->id}")->assertNoContent();
        $this->assertDatabaseMissing('contact_links', ['id' => $link->id]);
    }

    public function test_admin_index_orders_by_sort_order_not_insertion_order(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $second = ContactLink::create(['type' => 'phone', 'value' => '+963000000', 'sort_order' => 2]);
        $first = ContactLink::create(['type' => 'email', 'value' => 'hi@example.com', 'sort_order' => 1]);

        $response = $this->getJson('/api/v1/admin/contact-links');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
    }

    public function test_a_member_cannot_manage_contact_links(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/contact-links')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Ecosystem/ContactLinkAdminTest.php`
Expected: FAIL — route `admin/contact-links` does not exist (404), and `App\Domain\Ecosystem\Models\ContactLink` does not exist.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_17_090002_create_contact_links_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_links', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('value');
            $table->string('label')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_links');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Domain/Ecosystem/Models/ContactLink.php`:

```php
<?php

namespace App\Domain\Ecosystem\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'value',
        'label',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
        ];
    }
}
```

- [ ] **Step 5: Write the resource**

Create `app/Http/Resources/ContactLinkResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContactLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'label' => $this->label,
            'sort_order' => $this->sort_order,
            'is_visible' => $this->is_visible,
        ];
    }
}
```

- [ ] **Step 6: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreContactLinkRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:255'],
            'value' => ['required', 'string', 'max:2048'],
            'label' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }
}
```

Create `app/Http/Requests/Admin/UpdateContactLinkRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

/**
 * Same shape as creating one — extending instead of copy-pasting the rules.
 */
class UpdateContactLinkRequest extends StoreContactLinkRequest {}
```

- [ ] **Step 7: Write the admin controller**

Create `app/Http/Controllers/Api/V1/Admin/ContactLinkController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\ContactLink;
use App\Http\Requests\Admin\StoreContactLinkRequest;
use App\Http\Requests\Admin\UpdateContactLinkRequest;
use App\Http\Resources\ContactLinkResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactLinkController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return ContactLink::class;
    }

    protected function resourceClass(): string
    {
        return ContactLinkResource::class;
    }

    /**
     * `contact_links` orders by `sort_order`, not the base class's
     * hardcoded `order` column — skip the base ordering and add ours
     * through the existing filter hook instead of duplicating index().
     */
    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->orderBy('sort_order');
    }

    /**
     * `sort_order`/`is_visible` are set explicitly here, not left to the
     * migration's column defaults — Eloquent doesn't re-fetch DB-side
     * defaults into an unrefreshed model, so omitting either would
     * otherwise come back `null` in this very response even though the DB
     * row is correctly `0`/`true` (the same lesson already documented for
     * `FounderController::store`/`PartnerController::store`).
     */
    public function store(StoreContactLinkRequest $request): ContactLinkResource
    {
        return new ContactLinkResource(ContactLink::create(array_merge(
            ['sort_order' => 0, 'is_visible' => true],
            $request->validated()
        )));
    }

    public function update(UpdateContactLinkRequest $request, ContactLink $contactLink): JsonResponse
    {
        $contactLink->update($request->validated());

        return response()->json(['message' => __('api.admin.contact_link_updated')]);
    }
}
```

- [ ] **Step 8: Register the routes**

In `routes/api/v1/admin.php`, add the import next to the existing `use App\Http\Controllers\Api\V1\Admin\CompanyMemberController;` line:

```php
use App\Http\Controllers\Api\V1\Admin\ContactLinkController;
```

Then add this line right after `Route::apiResource('plans', PlanController::class);` (and before the `Route::get('exchange-rates', ...)` line):

```php
// Contact Links — public org content (social/app-store/website/phone
// links), admin-managed. Same permission tier as Founders/Partners (no
// narrower role:admin group) — see
// docs/decisions/profile-fields-completion-score-contact-links.md.
Route::apiResource('contact-links', ContactLinkController::class)
    ->parameters(['contact-links' => 'contactLink']);
```

- [ ] **Step 9: Add the lang keys**

In `lang/en/api.php`, add this line to the `'admin'` array, next to the existing `'partner_updated' => 'Partner updated.',` line:

```php
        'contact_link_updated' => 'Contact link updated.',
```

In `lang/ar/api.php`, add this line to the `'admin'` array, next to the existing `'partner_updated' => 'تم تحديث الشريك.',` line:

```php
        'contact_link_updated' => 'تم تحديث رابط التواصل.',
```

- [ ] **Step 10: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Ecosystem/ContactLinkAdminTest.php`
Expected: PASS (all 5 tests green).

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_08_17_090002_create_contact_links_table.php app/Domain/Ecosystem/Models/ContactLink.php app/Http/Resources/ContactLinkResource.php app/Http/Requests/Admin/StoreContactLinkRequest.php app/Http/Requests/Admin/UpdateContactLinkRequest.php app/Http/Controllers/Api/V1/Admin/ContactLinkController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Ecosystem/ContactLinkAdminTest.php
git commit -m "feat(ecosystem): add ContactLink admin CRUD"
```

---

## Task 5: `ContactLink` public read

**Files:**
- Create: `app/Http/Controllers/Api/V1/Public/ContactLinkController.php`
- Modify: `routes/api/v1/public.php`
- Test: Create `tests/Feature/Ecosystem/ContactLinkPublicTest.php`

**Interfaces:**
- Consumes: `App\Domain\Ecosystem\Models\ContactLink`, `App\Http\Resources\ContactLinkResource` (both from Task 4).

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Ecosystem/ContactLinkPublicTest.php`:

```php
<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\ContactLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactLinkPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_read_excludes_hidden_links_and_orders_by_sort_order(): void
    {
        $hidden = ContactLink::create(['type' => 'phone', 'value' => '+963000000', 'sort_order' => 0, 'is_visible' => false]);
        $second = ContactLink::create(['type' => 'website', 'value' => 'https://example.com', 'sort_order' => 2, 'is_visible' => true]);
        $first = ContactLink::create(['type' => 'email', 'value' => 'hi@example.com', 'sort_order' => 1, 'is_visible' => true]);

        $response = $this->getJson('/api/v1/contact-links');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_public_read_requires_no_authentication(): void
    {
        ContactLink::create(['type' => 'email', 'value' => 'hi@example.com']);

        $this->getJson('/api/v1/contact-links')->assertOk();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/Ecosystem/ContactLinkPublicTest.php`
Expected: FAIL — route `/api/v1/contact-links` does not exist (404).

- [ ] **Step 3: Write the public controller**

Create `app/Http/Controllers/Api/V1/Public/ContactLinkController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Models\ContactLink;
use App\Http\Resources\ContactLinkResource;
use Illuminate\Database\Eloquent\Builder;

class ContactLinkController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return ContactLink::class;
    }

    protected function resourceClass(): string
    {
        return ContactLinkResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->where('is_visible', true)->orderBy('sort_order');
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api/v1/public.php`, add the import next to the existing `use App\Http\Controllers\Api\V1\Public\CommunityMemberController;` line:

```php
use App\Http\Controllers\Api\V1\Public\ContactLinkController;
```

Then add this line after `Route::get('plans', [PlanController::class, 'index']);` and before the `member-directory` line:

```php
Route::get('contact-links', [ContactLinkController::class, 'index']);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Ecosystem/ContactLinkPublicTest.php`
Expected: PASS (both tests green).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/Public/ContactLinkController.php routes/api/v1/public.php tests/Feature/Ecosystem/ContactLinkPublicTest.php
git commit -m "feat(ecosystem): add ContactLink public read endpoint"
```

---

## Task 6: Guard test — the ADD Member tier stays unbuilt

**Files:**
- Create: `tests/Guards/AddMemberTierUnbuiltTest.php`

**Interfaces:** None — pure source-scanning guard, no production code interface.

- [ ] **Step 1: Write the guard test**

Create `tests/Guards/AddMemberTierUnbuiltTest.php`:

```php
<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * The 2026-08-17 profile-completion phase investigated whether an "ADD
 * Member" tier/promotion mechanism already existed to wire the completion
 * score into (docs/decisions/profile-fields-completion-score-contact-links.md
 * §3). It doesn't: `add_members` is named in the backend build plan and
 * D.12 as a future rung of the Community → ADD Members → ADD Club ladder,
 * but no table, model, or promotion logic exists in code. Per that
 * decision, this phase reports the gap rather than inventing a tier system
 * as a side effect — this guard (mirroring AddClubUnmodeledTest for the
 * tier above this one) keeps it that way until someone deliberately
 * removes it alongside building the real thing.
 */
class AddMemberTierUnbuiltTest extends TestCase
{
    use ScansSourceFiles;

    public function test_no_table_model_or_route_models_the_add_member_tier(): void
    {
        $violations = [];

        foreach (['app/Domain', 'database/migrations', 'routes'] as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path => $contents) {
                if (preg_match('/\badd_members\b/i', $contents) || preg_match('/\bAddMember\b/', $contents)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, "add_members / AddMember stays unbuilt until the membership-ladder decision is made:\n".implode("\n", $violations));
    }
}
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `php artisan test tests/Guards/AddMemberTierUnbuiltTest.php`
Expected: PASS — nothing in the current codebase (including everything added by Tasks 1–5 of this plan) mentions `add_members` or `AddMember`.

- [ ] **Step 3: Commit**

```bash
git add tests/Guards/AddMemberTierUnbuiltTest.php
git commit -m "test(guards): add_members / AddMember tier stays unmodeled"
```

---

## Task 7: Postman collection updates

**Files:**
- Modify: `postman/ADD-OS.postman_collection.json`

**Interfaces:** None — documentation only.

- [ ] **Step 1: Add the "Contact Links" admin folder**

In `postman/ADD-OS.postman_collection.json`, find this exact text (the boundary between the end of the "Partners" folder and the start of the "Community Members" folder, inside `Admin (Dashboard) > Content`):

```
                                                                    }
                                                                ]
                                                   },
                                                   {
                                                       "name":  "Community Members",
```

Replace it with (inserting a new "Contact Links" folder between "Partners" and "Community Members"):

```
                                                                    }
                                                                ]
                                                   },
                                                   {
                                                       "name":  "Contact Links",
                                                       "item":  [
                                                                    {
                                                                        "name":  "List Contact Links",
                                                                        "request":  {
                                                                                        "method":  "GET",
                                                                                        "header":  [
                                                                                                       {
                                                                                                           "key":  "lang",
                                                                                                           "value":  "{{lang}}",
                                                                                                           "type":  "text"
                                                                                                       }
                                                                                                   ],
                                                                                        "url":  {
                                                                                                    "raw":  "{{base_url}}/api/v1/admin/contact-links",
                                                                                                    "host":  [
                                                                                                                 "{{base_url}}"
                                                                                                             ],
                                                                                                    "path":  [
                                                                                                                 "api",
                                                                                                                 "v1",
                                                                                                                 "admin",
                                                                                                                 "contact-links"
                                                                                                             ]
                                                                                                }
                                                                                    }
                                                                    },
                                                                    {
                                                                        "name":  "Create Contact Link",
                                                                        "event":  [
                                                                                      {
                                                                                          "listen":  "test",
                                                                                          "script":  {
                                                                                                         "type":  "text/javascript",
                                                                                                         "exec":  [
                                                                                                                      "if (pm.response.code === 201 || pm.response.code === 200) {",
                                                                                                                      "    pm.collectionVariables.set('contact_link_id', pm.response.json().data.id);",
                                                                                                                      "}"
                                                                                                                  ]
                                                                                                     }
                                                                                      }
                                                                                  ],
                                                                        "request":  {
                                                                                        "method":  "POST",
                                                                                        "header":  [
                                                                                                       {
                                                                                                           "key":  "Content-Type",
                                                                                                           "value":  "application/json"
                                                                                                       },
                                                                                                       {
                                                                                                           "key":  "lang",
                                                                                                           "value":  "{{lang}}",
                                                                                                           "type":  "text"
                                                                                                       }
                                                                                                   ],
                                                                                        "body":  {
                                                                                                     "mode":  "raw",
                                                                                                     "raw":  "{\n  \"type\": \"social_instagram\",\n  \"value\": \"https://instagram.com/adddistrict\",\n  \"label\": \"Instagram\",\n  \"sort_order\": 1\n}",
                                                                                                     "options":  {
                                                                                                                     "raw":  {
                                                                                                                                 "language":  "json"
                                                                                                                             }
                                                                                                                 }
                                                                                                 },
                                                                                        "url":  {
                                                                                                    "raw":  "{{base_url}}/api/v1/admin/contact-links",
                                                                                                    "host":  [
                                                                                                                 "{{base_url}}"
                                                                                                             ],
                                                                                                    "path":  [
                                                                                                                 "api",
                                                                                                                 "v1",
                                                                                                                 "admin",
                                                                                                                 "contact-links"
                                                                                                             ]
                                                                                                },
                                                                                        "description":  "`type` is an open string, not a fixed list — a new platform is a row insert here, never a code change or migration."
                                                                                    }
                                                                    },
                                                                    {
                                                                        "name":  "Get Contact Link",
                                                                        "request":  {
                                                                                        "method":  "GET",
                                                                                        "header":  [
                                                                                                       {
                                                                                                           "key":  "lang",
                                                                                                           "value":  "{{lang}}",
                                                                                                           "type":  "text"
                                                                                                       }
                                                                                                   ],
                                                                                        "url":  {
                                                                                                    "raw":  "{{base_url}}/api/v1/admin/contact-links/{{contact_link_id}}",
                                                                                                    "host":  [
                                                                                                                 "{{base_url}}"
                                                                                                             ],
                                                                                                    "path":  [
                                                                                                                 "api",
                                                                                                                 "v1",
                                                                                                                 "admin",
                                                                                                                 "contact-links",
                                                                                                                 "{{contact_link_id}}"
                                                                                                             ]
                                                                                                }
                                                                                    }
                                                                    },
                                                                    {
                                                                        "name":  "Update Contact Link",
                                                                        "request":  {
                                                                                        "method":  "PUT",
                                                                                        "header":  [
                                                                                                       {
                                                                                                           "key":  "Content-Type",
                                                                                                           "value":  "application/json"
                                                                                                       },
                                                                                                       {
                                                                                                           "key":  "lang",
                                                                                                           "value":  "{{lang}}",
                                                                                                           "type":  "text"
                                                                                                       }
                                                                                                   ],
                                                                                        "body":  {
                                                                                                     "mode":  "raw",
                                                                                                     "raw":  "{\n  \"type\": \"social_instagram\",\n  \"value\": \"https://instagram.com/adddistrict.new\",\n  \"sort_order\": 2,\n  \"is_visible\": true\n}",
                                                                                                     "options":  {
                                                                                                                     "raw":  {
                                                                                                                                 "language":  "json"
                                                                                                                             }
                                                                                                                 }
                                                                                                 },
                                                                                        "url":  {
                                                                                                    "raw":  "{{base_url}}/api/v1/admin/contact-links/{{contact_link_id}}",
                                                                                                    "host":  [
                                                                                                                 "{{base_url}}"
                                                                                                             ],
                                                                                                    "path":  [
                                                                                                                 "api",
                                                                                                                 "v1",
                                                                                                                 "admin",
                                                                                                                 "contact-links",
                                                                                                                 "{{contact_link_id}}"
                                                                                                             ]
                                                                                                },
                                                                                        "description":  "Returns `{\"message\": \"...\"}` on success, not the updated resource. Also how to reorder (`sort_order`) or toggle visibility (`is_visible`) — both are plain fields on this same endpoint, not separate actions."
                                                                                    }
                                                                    },
                                                                    {
                                                                        "name":  "Delete Contact Link",
                                                                        "request":  {
                                                                                        "method":  "DELETE",
                                                                                        "header":  [
                                                                                                       {
                                                                                                           "key":  "lang",
                                                                                                           "value":  "{{lang}}",
                                                                                                           "type":  "text"
                                                                                                       }
                                                                                                   ],
                                                                                        "url":  {
                                                                                                    "raw":  "{{base_url}}/api/v1/admin/contact-links/{{contact_link_id}}",
                                                                                                    "host":  [
                                                                                                                 "{{base_url}}"
                                                                                                             ],
                                                                                                    "path":  [
                                                                                                                 "api",
                                                                                                                 "v1",
                                                                                                                 "admin",
                                                                                                                 "contact-links",
                                                                                                                 "{{contact_link_id}}"
                                                                                                             ]
                                                                                                }
                                                                                    }
                                                                    }
                                                                ]
                                                   },
                                                   {
                                                       "name":  "Community Members",
```

- [ ] **Step 2: Add the "Get Contact Links" public request**

In the same file, find this exact text (inside `Public (Site)`, the "Get Founders" request):

```
                                  {
                                      "name":  "Get Founders",
```

Replace it with (inserting "Get Contact Links" immediately before "Get Founders" — alphabetical position doesn't matter here since the existing list isn't alphabetized, so prepending is the simplest safe insertion point):

```
                                  {
                                      "name":  "Get Contact Links",
                                      "request":  {
                                                      "method":  "GET",
                                                      "header":  [
                                                                     {
                                                                         "key":  "lang",
                                                                         "value":  "{{lang}}",
                                                                         "type":  "text"
                                                                     }
                                                                 ],
                                                      "url":  {
                                                                  "raw":  "{{base_url}}/api/v1/contact-links",
                                                                  "host":  [
                                                                               "{{base_url}}"
                                                                           ],
                                                                  "path":  [
                                                                               "api",
                                                                               "v1",
                                                                               "contact-links"
                                                                           ]
                                                              },
                                                      "description":  "Only `is_visible = true` rows, ordered by `sort_order`. No authentication required."
                                                  }
                                  },
                                  {
                                      "name":  "Get Founders",
```

- [ ] **Step 3: Update the "Get Profile" and "Update Profile" requests with the new fields**

Find this exact text (the `Get Profile` request's `description`):

```
                                                                       "description":  "Returns the authenticated member in one payload: account fields (id, name, phone, email, preferred_language, preferred_currency, status — deliberately no `roles`, unlike `/auth/me`'s shared UserResource, since a member doesn't need their own Spatie roles echoed back) plus `personal` (`bio`, `city`, `avatar_url`) and `professional` (`job_title`, `company_name`, `industry`, `linkedin_url`). Profile fields are null until the member has ever saved one — there is no separate \"not found\" state; the underlying rows are only created on first PATCH."
```

(Note: the actual file may render the em-dash and curly quote as raw UTF-8 bytes rather than `—`/`'` — search for the unique substring `plus \`personal\` (\`bio\`, \`city\`, \`avatar_url\`)` if the escape-sequence form above isn't found verbatim, and replace the whole `"description"` line using that as the anchor.)

Replace it with:

```
                                                                       "description":  "Returns the authenticated member in one payload: account fields (id, name, phone, email, preferred_language, preferred_currency, status) plus `personal` (`bio`, `city`, `avatar_url`, `gender`) and `professional` (`job_title`, `company_name`, `industry`, `linkedin_url`, `instagram_url`, `behance_url`, `website_url`). Profile fields are null until the member has ever saved one. Also returns a top-level `completion` object (sibling of `data`): `score` (0-100), `threshold` (from the `profile.completion_threshold` setting), and `missing_fields`."
```

Find this exact text (the `Update Profile` request's `body.raw`):

```
                                                                                    "raw":  "{\n  \"bio\": \"Coffee and code.\",\n  \"city\": \"Aleppo\",\n  \"avatar_url\": \"https://example.com/avatar.png\",\n  \"job_title\": \"Founder\",\n  \"company_name\": \"ACME\",\n  \"industry\": \"Software\",\n  \"linkedin_url\": \"https://linkedin.com/in/example\"\n}",
```

Replace it with:

```
                                                                                    "raw":  "{\n  \"bio\": \"Coffee and code.\",\n  \"city\": \"Aleppo\",\n  \"avatar_url\": \"https://example.com/avatar.png\",\n  \"gender\": \"male\",\n  \"job_title\": \"Founder\",\n  \"company_name\": \"ACME\",\n  \"industry\": \"Software\",\n  \"linkedin_url\": \"https://linkedin.com/in/example\",\n  \"instagram_url\": \"https://instagram.com/example\",\n  \"behance_url\": \"https://behance.net/example\",\n  \"website_url\": \"https://example.com\"\n}",
```

- [ ] **Step 4: Validate the JSON is still well-formed**

Run: `php -r "json_decode(file_get_contents('postman/ADD-OS.postman_collection.json'), true) === null ? exit(1) : exit(0);"`
Expected: exit code `0` (valid JSON). If it exits `1`, re-check bracket balance around each edit.

- [ ] **Step 5: Commit**

```bash
git add postman/ADD-OS.postman_collection.json
git commit -m "docs(postman): add Contact Links folder, update Profile examples"
```

---

## Task 8: Decision doc

**Files:**
- Create: `docs/decisions/profile-fields-completion-score-contact-links.md`
- Modify: `docs/decisions/README.md`

**Interfaces:** None — documentation only.

- [ ] **Step 1: Write the decision doc**

Create `docs/decisions/profile-fields-completion-score-contact-links.md`:

```markdown
# Profile fields, completion score, and contact_links

**Status:** resolved 2026-08-17. **Owner:** Maryam Asha.
**Type:** design doc, written against the 2026-08-15 decision session
(decisions #11, #12, #15).

## What this adds

Four optional profile fields (`gender`, `instagram_url`, `behance_url`,
`website_url`), a derived profile-completion score exposed on
`GET /member/profile`, and a new `App\Domain\Ecosystem\ContactLink`
resource (admin-managed, publicly readable).

## Decision

- **`gender` lives on `user_personal_profiles`,** alongside `bio`/`city`/
  `avatar_url` — it's a personal, not professional, attribute. Column is a
  nullable `string` cast to a new `App\Domain\Identity\Enums\Gender` backed
  enum (`Male`, `Female`), matching this codebase's established convention
  for optional categorical fields (`ErrorLogPlatform`/`platform`,
  `private_office_requests.status`) — never a MySQL `ENUM` column
  (`tests/Guards/NoNewMysqlEnumColumnsTest.php`). Only two cases were
  chosen; the source spec left the value set unspecified, so this is an
  assumption, not a locked decision — flagged here for confirmation before
  production, the same way `settings-key-value-store.md` flagged its own
  unspecified numeric defaults.
- **`instagram_url`/`behance_url`/`website_url` live on
  `user_professional_profiles`,** alongside the existing `linkedin_url` —
  "keep all social/web links together" per the spec, and `linkedin_url`
  already set that precedent.
- **Completion score is computed on read, never cached.** Both profile
  relations (`personalProfile`, `professionalProfile`) are already loaded
  on every `GET /member/profile` call regardless — there is no hot path
  this adds cost to, and no-cache-at-all sidesteps the entire invalidation-
  correctness surface the spec warned about ("do not let it go stale
  silently") by having nothing to invalidate.
- **`profile.completion_threshold` needed no seeder change.** It was
  already seeded at `80` in the 2026-08-15 settings phase
  (`database/seeders/SettingSeeder.php`, flagged there as an assumption).
  This phase just reads it via `SettingService::get()` and reconfirms `80`
  as the working default.
- **`contact_links.type` is an open plain `string`, not a PHP backed enum
  and not a MySQL `ENUM`.** The spec's own precedent citation
  ("check `SettingScope`'s reasoning... it was designed with the same
  open-endedness in mind") turned out to be factually wrong on inspection —
  `SettingScope` is itself a closed backed enum with exactly one case
  today (`Global`). The actual open-string precedent in that same domain is
  `Setting::$key`: a freely composed, domain-owned, dot-namespaced string
  with no central enum coordinating every consumer. `contact_links.type`
  needs that same property, for a structural reason: the requirement
  "adding a platform is a row insert, never a code change or migration" is
  incompatible with a backed enum, whose cases are fixed at deploy time.
- **`ContactLink` lives in `App\Domain\Ecosystem`,** mirroring `Founder`/
  `Partner` exactly — public-facing, admin-managed organisational content,
  same permission tier (`role:admin|operations`, no narrower `role:admin`
  group), same `AdminResourceController`/`PublicResourceController`
  two-tier pattern. Unlike `PaymentMethod` (forward-earmarked into a
  not-yet-built `Finance` domain because the backend build plan already
  named that home ahead of time), no forward earmark exists for
  `contact_links` anywhere in the build plan — `Ecosystem` already fully
  fits the shape today, so no new domain is warranted.
- **No ADD Member promotion/tier mechanism is built.** Investigated per the
  spec's §3 instruction: no table, model, enum, or route models an "ADD
  Member" tier anywhere in this codebase today. `add_members` is named
  only as a future table in `docs/architecture/2026-08-08-backend-build-
  plan.md` (Ecosystem domain, Phase 9) and in `tests/Guards/
  CommunityMembersNoUserLinkTest.php`'s docblock, which calls the question
  of whether it optionally links to a `users` account (D.12) "distinct...
  and stays undecided." The three RBAC roles (`member`/`operations`/
  `admin`) are authorization roles, not membership tiers, and are
  unrelated. `App\Domain\Membership` (`Plan`/`Membership`/`Wallet`) is a
  subscription-billing concept, also unrelated. Per the spec's own
  instruction, this phase stops here rather than inventing a tier system:
  the completion score is exposed (`score`/`threshold`/`missing_fields` on
  `GET /member/profile`) as the future eligibility signal, and
  `tests/Guards/AddMemberTierUnbuiltTest.php` (mirroring the existing
  `AddClubUnmodeledTest` for the tier above this one) keeps the concept
  unmodeled until that decision is made.
- **No notification code is added.** Also investigated per the spec's §0
  instruction to reuse "whatever notification mechanism was used for
  reception/approval notifications in prior phases": no such mechanism
  exists anywhere in this codebase — no `Illuminate\Notifications\
  Notification` subclass, no mail, no reception/approval notification was
  ever built. `App\Domain\Identity\Models\NotificationLog` exists as a
  migration/model but nothing writes to it; it's dormant scaffolding kept
  from an earlier ERD. Since no promotion mechanism exists to notify about
  (previous bullet), this phase has nothing to wire a notification to
  either. Flagged here for whoever eventually builds the ADD Member tier —
  `NotificationLog` and `User`'s existing `Notifiable` trait are the
  pieces already in place for that future work.

## Why

See "Decision" above — each bullet states its own reasoning inline.

## What this changed in code

- `database/migrations/2026_08_17_090000_add_gender_to_user_personal_profiles_table.php`
- `database/migrations/2026_08_17_090001_add_social_links_to_user_professional_profiles_table.php`
- `database/migrations/2026_08_17_090002_create_contact_links_table.php`
- `App\Domain\Identity\Enums\Gender`
- `App\Domain\Identity\Models\{UserPersonalProfile,UserProfessionalProfile}` (fillable/casts)
- `App\Domain\Identity\Services\ProfileCompletionService`
- `App\Domain\Ecosystem\Models\ContactLink`
- `App\Http\Resources\{UserPersonalProfileResource,UserProfessionalProfileResource,ContactLinkResource}`
- `App\Http\Requests\Member\UpdateProfileRequest`, `App\Http\Requests\Admin\{Store,Update}ContactLinkRequest`
- `App\Http\Controllers\Api\V1\Member\ProfileController` (score wiring)
- `App\Http\Controllers\Api\V1\{Admin,Public}\ContactLinkController`
- `routes/api/v1/{admin,public}.php`
- `lang/{en,ar}/api.php` (`admin.contact_link_updated`)
- `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (extended with the `UserPersonalProfile` entry)
- `tests/Guards/AddMemberTierUnbuiltTest.php` (new)
- `postman/ADD-OS.postman_collection.json` (`Content > Contact Links`, `Public (Site) > Get Contact Links`, updated `Profile` examples)

## Guard

[`EnumColumnsHaveBackedEnumCastsTest`](../../tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php)
covers the `gender` enum cast. [`AddMemberTierUnbuiltTest`](../../tests/Guards/AddMemberTierUnbuiltTest.php)
covers the ADD Member tier staying unmodeled. No dedicated guard exists for
the completion-score weights themselves or for `contact_links.type`
staying an open string — both are covered by `tests/Unit/Domain/Identity/
ProfileCompletionServiceTest.php` and `tests/Feature/Ecosystem/
ContactLinkAdminTest.php` respectively, rather than a source-scanning
guard, since neither is a "this must never exist" invariant.
```

- [ ] **Step 2: Add the doc to the decision register index**

In `docs/decisions/README.md`, add this line to the "Design docs" bullet
list, after the existing `reception-operations-scope.md` line:

```markdown
- [profile-fields-completion-score-contact-links.md](profile-fields-completion-score-contact-links.md) — gender/social-link profile fields, the derived completion score, `contact_links`, and the confirmed-absent ADD Member tier
```

- [ ] **Step 3: Commit**

```bash
git add docs/decisions/profile-fields-completion-score-contact-links.md docs/decisions/README.md
git commit -m "docs(decisions): profile fields, completion score, contact_links"
```

---

## Final verification

- [ ] **Run the full test suite**

Run: `composer test`
Expected: PASS, zero failures, including every guard test.

- [ ] **Run Pint**

Run: `./vendor/bin/pint --test`
Expected: no style violations. If any are reported, run `./vendor/bin/pint` (no `--test`) to fix, then re-run the full test suite once more before the final commit.
