# User Profiles + Member Directory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** let a member self-fill a personal and a professional profile, opt in/out of appearing on a new public member directory, and expose that directory as a read-only public endpoint.

**Architecture:** two new 1:1 tables off `users`, each with a thin member-scoped controller (`show`/`update`, upsert semantics). Consent reuses the existing `consents` table as-is (`ConsentType::PublicDirectory`), with new grant/revoke helpers on the `Consent` model. The public directory reuses the existing `PublicResourceController` pattern, filtering `users` by active consent + at least one filled profile.

**Tech Stack:** Laravel 12, PHPUnit, SQLite (in-memory, tests).

## Global Constraints

- These tables are member-editable, not admin-only — the key difference from admin-managed `community_members`.
- No functional gating: filling a profile is never wired to membership-ladder logic.
- Profile fields are plain strings (self-authored, single language), not the `{ar, en}` JSON translation pattern used for admin-curated bilingual content.
- `community_members` must never gain a `user_id`/`linked_user_id` column or relation — this feature is a fully separate table (`tests/Guards/CommunityMembersNoUserLinkTest.php` must keep passing untouched).
- The public directory response never includes `phone`/`email`, regardless of consent.
- Every new feature test seeds roles via `RoleSeeder` and authenticates via `Laravel\Sanctum\Sanctum::actingAs($user, ['*'])`.

---

### Task 1: `user_personal_profiles` / `user_professional_profiles` tables + models

**Files:**
- Create: `database/migrations/2026_08_09_160002_create_user_personal_profiles_table.php`
- Create: `database/migrations/2026_08_09_160003_create_user_professional_profiles_table.php`
- Create: `app/Domain/Identity/Models/UserPersonalProfile.php`
- Create: `app/Domain/Identity/Models/UserProfessionalProfile.php`
- Modify: `app/Domain/Identity/Models/User.php` (add `personalProfile()`/`professionalProfile()` relations)
- Test: `tests/Unit/Domain/Identity/UserProfileRelationsTest.php`

**Interfaces:**
- Produces: `User::personalProfile(): HasOne`, `User::professionalProfile(): HasOne` — consumed by Tasks 2, 3, 5.
- Produces: `UserPersonalProfile` fields `bio`, `city`, `avatar_url`; `UserProfessionalProfile` fields `job_title`, `company_name`, `industry`, `linkedin_url`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Domain/Identity/UserProfileRelationsTest.php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Models\UserProfessionalProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_has_one_personal_profile(): void
    {
        $user = User::factory()->create();
        UserPersonalProfile::create(['user_id' => $user->id, 'bio' => 'Hello', 'city' => 'Aleppo']);

        $this->assertSame('Hello', $user->personalProfile->bio);
    }

    public function test_a_user_has_one_professional_profile(): void
    {
        $user = User::factory()->create();
        UserProfessionalProfile::create(['user_id' => $user->id, 'job_title' => 'Engineer']);

        $this->assertSame('Engineer', $user->professionalProfile->job_title);
    }

    public function test_user_id_is_unique_on_both_tables(): void
    {
        $user = User::factory()->create();
        UserPersonalProfile::create(['user_id' => $user->id]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        UserPersonalProfile::create(['user_id' => $user->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Identity/UserProfileRelationsTest.php`
Expected: FAIL — classes don't exist yet.

- [ ] **Step 3: Write the migrations**

```php
<?php
// database/migrations/2026_08_09_160002_create_user_personal_profiles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_personal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('bio')->nullable();
            $table->string('city')->nullable();
            $table->string('avatar_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_personal_profiles');
    }
};
```

```php
<?php
// database/migrations/2026_08_09_160003_create_user_professional_profiles_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_professional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->string('company_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_professional_profiles');
    }
};
```

- [ ] **Step 4: Write the models**

```php
<?php
// app/Domain/Identity/Models/UserPersonalProfile.php

namespace App\Domain\Identity\Models;

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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

```php
<?php
// app/Domain/Identity/Models/UserProfessionalProfile.php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfessionalProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'job_title',
        'company_name',
        'industry',
        'linkedin_url',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Add relations to User**

In `app/Domain/Identity/Models/User.php`, add these imports alongside the existing ones:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

and add these two methods (near `branchMemberships()`/`branches()`):

```php
    public function personalProfile(): HasOne
    {
        return $this->hasOne(UserPersonalProfile::class);
    }

    public function professionalProfile(): HasOne
    {
        return $this->hasOne(UserProfessionalProfile::class);
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Identity/UserProfileRelationsTest.php`
Expected: PASS (all 3 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_09_160002_create_user_personal_profiles_table.php database/migrations/2026_08_09_160003_create_user_professional_profiles_table.php app/Domain/Identity/Models/UserPersonalProfile.php app/Domain/Identity/Models/UserProfessionalProfile.php app/Domain/Identity/Models/User.php tests/Unit/Domain/Identity/UserProfileRelationsTest.php
git commit -m "feat: add user_personal_profiles and user_professional_profiles tables"
```

---

### Task 2: Personal profile endpoints

**Files:**
- Create: `app/Http/Requests/Member/UpdatePersonalProfileRequest.php`
- Create: `app/Http/Resources/UserPersonalProfileResource.php`
- Create: `app/Http/Controllers/Api/V1/Member/PersonalProfileController.php`
- Modify: `routes/api/v1/member.php` (add 2 routes)
- Test: `tests/Feature/Member/PersonalProfileControllerTest.php`

**Interfaces:**
- Consumes: `User::personalProfile()` (Task 1).
- Produces: `GET/PATCH /api/v1/member/profile/personal`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Member/PersonalProfileControllerTest.php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_member_can_fill_their_personal_profile(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/profile/personal', [
            'bio' => 'Coffee and code.',
            'city' => 'Aleppo',
            'avatar_url' => 'https://example.com/avatar.png',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.bio', 'Coffee and code.');
        $this->assertDatabaseHas('user_personal_profiles', [
            'user_id' => $member->id,
            'city' => 'Aleppo',
        ]);
    }

    public function test_updating_again_upserts_rather_than_creating_a_second_row(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/profile/personal', ['city' => 'Aleppo'])->assertOk();
        $this->patchJson('/api/v1/member/profile/personal', ['city' => 'Damascus'])->assertOk();

        $this->assertDatabaseCount('user_personal_profiles', 1);
        $this->assertDatabaseHas('user_personal_profiles', ['user_id' => $member->id, 'city' => 'Damascus']);
    }

    public function test_show_returns_null_fields_when_no_profile_exists_yet(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile/personal');

        $response->assertOk();
        $response->assertJsonPath('data.bio', null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Member/PersonalProfileControllerTest.php`
Expected: FAIL — routes don't exist (404).

- [ ] **Step 3: Write the Form Request and Resource**

```php
<?php
// app/Http/Requests/Member/UpdatePersonalProfileRequest.php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonalProfileRequest extends FormRequest
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
        ];
    }
}
```

```php
<?php
// app/Http/Resources/UserPersonalProfileResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserPersonalProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bio' => $this->bio,
            'city' => $this->city,
            'avatar_url' => $this->avatar_url,
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/Member/PersonalProfileController.php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserPersonalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdatePersonalProfileRequest;
use App\Http\Resources\UserPersonalProfileResource;
use Illuminate\Http\Request;

class PersonalProfileController extends Controller
{
    public function show(Request $request): UserPersonalProfileResource
    {
        return new UserPersonalProfileResource(
            $request->user()->personalProfile ?? new UserPersonalProfile()
        );
    }

    public function update(UpdatePersonalProfileRequest $request): UserPersonalProfileResource
    {
        $profile = UserPersonalProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated()
        );

        return new UserPersonalProfileResource($profile);
    }
}
```

- [ ] **Step 5: Wire the routes**

In `routes/api/v1/member.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Member\PersonalProfileController;
```

and add:

```php
Route::get('profile/personal', [PersonalProfileController::class, 'show']);
Route::patch('profile/personal', [PersonalProfileController::class, 'update']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Member/PersonalProfileControllerTest.php`
Expected: PASS (all 3 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Member/UpdatePersonalProfileRequest.php app/Http/Resources/UserPersonalProfileResource.php app/Http/Controllers/Api/V1/Member/PersonalProfileController.php routes/api/v1/member.php tests/Feature/Member/PersonalProfileControllerTest.php
git commit -m "feat: add member personal profile endpoint"
```

---

### Task 3: Professional profile endpoints

**Files:**
- Create: `app/Http/Requests/Member/UpdateProfessionalProfileRequest.php`
- Create: `app/Http/Resources/UserProfessionalProfileResource.php`
- Create: `app/Http/Controllers/Api/V1/Member/ProfessionalProfileController.php`
- Modify: `routes/api/v1/member.php` (add 2 routes)
- Test: `tests/Feature/Member/ProfessionalProfileControllerTest.php`

**Interfaces:**
- Consumes: `User::professionalProfile()` (Task 1).
- Produces: `GET/PATCH /api/v1/member/profile/professional`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Member/ProfessionalProfileControllerTest.php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfessionalProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_member_can_fill_their_professional_profile(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/profile/professional', [
            'job_title' => 'Founder',
            'company_name' => 'ACME',
            'industry' => 'Software',
            'linkedin_url' => 'https://linkedin.com/in/example',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.job_title', 'Founder');
        $this->assertDatabaseHas('user_professional_profiles', [
            'user_id' => $member->id,
            'company_name' => 'ACME',
        ]);
    }

    public function test_updating_again_upserts_rather_than_creating_a_second_row(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/profile/professional', ['job_title' => 'Founder'])->assertOk();
        $this->patchJson('/api/v1/member/profile/professional', ['job_title' => 'CEO'])->assertOk();

        $this->assertDatabaseCount('user_professional_profiles', 1);
        $this->assertDatabaseHas('user_professional_profiles', ['user_id' => $member->id, 'job_title' => 'CEO']);
    }

    public function test_show_returns_null_fields_when_no_profile_exists_yet(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/member/profile/professional');

        $response->assertOk();
        $response->assertJsonPath('data.job_title', null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Member/ProfessionalProfileControllerTest.php`
Expected: FAIL — routes don't exist (404).

- [ ] **Step 3: Write the Form Request and Resource**

```php
<?php
// app/Http/Requests/Member/UpdateProfessionalProfileRequest.php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfessionalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'job_title' => ['nullable', 'string', 'max:120'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:120'],
            'linkedin_url' => ['nullable', 'url'],
        ];
    }
}
```

```php
<?php
// app/Http/Resources/UserProfessionalProfileResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfessionalProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'job_title' => $this->job_title,
            'company_name' => $this->company_name,
            'industry' => $this->industry,
            'linkedin_url' => $this->linkedin_url,
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/Member/ProfessionalProfileController.php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateProfessionalProfileRequest;
use App\Http\Resources\UserProfessionalProfileResource;
use Illuminate\Http\Request;

class ProfessionalProfileController extends Controller
{
    public function show(Request $request): UserProfessionalProfileResource
    {
        return new UserProfessionalProfileResource(
            $request->user()->professionalProfile ?? new UserProfessionalProfile()
        );
    }

    public function update(UpdateProfessionalProfileRequest $request): UserProfessionalProfileResource
    {
        $profile = UserProfessionalProfile::updateOrCreate(
            ['user_id' => $request->user()->id],
            $request->validated()
        );

        return new UserProfessionalProfileResource($profile);
    }
}
```

- [ ] **Step 5: Wire the routes**

In `routes/api/v1/member.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Member\ProfessionalProfileController;
```

and add:

```php
Route::get('profile/professional', [ProfessionalProfileController::class, 'show']);
Route::patch('profile/professional', [ProfessionalProfileController::class, 'update']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Member/ProfessionalProfileControllerTest.php`
Expected: PASS (all 3 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Member/UpdateProfessionalProfileRequest.php app/Http/Resources/UserProfessionalProfileResource.php app/Http/Controllers/Api/V1/Member/ProfessionalProfileController.php routes/api/v1/member.php tests/Feature/Member/ProfessionalProfileControllerTest.php
git commit -m "feat: add member professional profile endpoint"
```

---

### Task 4: Public-directory consent (grant/revoke)

**Files:**
- Modify: `app/Domain/Identity/Models/Consent.php` (add `scopeActive`, `grant`, `revokeActive`, `hasActive`)
- Modify: `app/Domain/Identity/Models/User.php` (add `consents()` relation)
- Create: `app/Http/Requests/Member/UpdatePublicDirectoryConsentRequest.php`
- Create: `app/Http/Controllers/Api/V1/Member/PublicDirectoryConsentController.php`
- Modify: `routes/api/v1/member.php` (add 1 route)
- Test: `tests/Feature/Member/PublicDirectoryConsentControllerTest.php`

**Interfaces:**
- Produces: `Consent::grant(ConsentSubjectType, int, ConsentType): Consent`, `Consent::revokeActive(ConsentSubjectType, int, ConsentType): void`, `Consent::hasActive(ConsentSubjectType, int, ConsentType): bool` (static) — consumed by Task 5.
- Produces: `User::consents(): HasMany` — consumed by Task 5.
- Produces: `PATCH /api/v1/member/consents/public-directory`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Member/PublicDirectoryConsentControllerTest.php

namespace Tests\Feature\Member;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicDirectoryConsentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_member_can_grant_public_directory_consent(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true]);

        $response->assertOk();
        $response->assertJsonPath('granted', true);
        $this->assertDatabaseHas('consents', [
            'subject_type' => 'user',
            'subject_id' => $member->id,
            'consent_type' => 'public_directory',
        ]);
    }

    public function test_a_member_can_revoke_public_directory_consent(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $response = $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => false]);

        $response->assertOk();
        $response->assertJsonPath('granted', false);
    }

    public function test_re_granting_after_revoke_creates_a_new_row_preserving_history(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => false])->assertOk();
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();

        $this->assertDatabaseCount('consents', 2);
    }

    public function test_granting_twice_in_a_row_does_not_create_a_duplicate_active_row(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();

        $this->assertDatabaseCount('consents', 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Member/PublicDirectoryConsentControllerTest.php`
Expected: FAIL — route doesn't exist (404).

- [ ] **Step 3: Add helpers to Consent and a relation on User**

In `app/Domain/Identity/Models/Consent.php`, add these imports:

```php
use Illuminate\Database\Eloquent\Builder;
```

and add these methods to the class:

```php
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public static function grant(ConsentSubjectType $subjectType, int $subjectId, ConsentType $consentType): self
    {
        return static::create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'consent_type' => $consentType,
            'granted_at' => now(),
        ]);
    }

    public static function revokeActive(ConsentSubjectType $subjectType, int $subjectId, ConsentType $consentType): void
    {
        static::query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->where('consent_type', $consentType->value)
            ->active()
            ->update(['revoked_at' => now()]);
    }

    public static function hasActive(ConsentSubjectType $subjectType, int $subjectId, ConsentType $consentType): bool
    {
        return static::query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->where('consent_type', $consentType->value)
            ->active()
            ->exists();
    }
```

In `app/Domain/Identity/Models/User.php`, add these imports:

```php
use App\Domain\Identity\Enums\ConsentSubjectType;
use Illuminate\Database\Eloquent\Relations\HasMany;
```

(note: `HasMany` may already be imported — check before duplicating) and add:

```php
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class, 'subject_id')->where('subject_type', ConsentSubjectType::User->value);
    }
```

- [ ] **Step 4: Write the Form Request and controller**

```php
<?php
// app/Http/Requests/Member/UpdatePublicDirectoryConsentRequest.php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePublicDirectoryConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'granted' => ['required', 'boolean'],
        ];
    }
}
```

```php
<?php
// app/Http/Controllers/Api/V1/Member/PublicDirectoryConsentController.php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Enums\ConsentSubjectType;
use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\Consent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdatePublicDirectoryConsentRequest;
use Illuminate\Http\JsonResponse;

class PublicDirectoryConsentController extends Controller
{
    public function update(UpdatePublicDirectoryConsentRequest $request): JsonResponse
    {
        $user = $request->user();
        $granted = $request->boolean('granted');
        $alreadyActive = Consent::hasActive(ConsentSubjectType::User, $user->id, ConsentType::PublicDirectory);

        if ($granted && ! $alreadyActive) {
            Consent::grant(ConsentSubjectType::User, $user->id, ConsentType::PublicDirectory);
        } elseif (! $granted) {
            Consent::revokeActive(ConsentSubjectType::User, $user->id, ConsentType::PublicDirectory);
        }

        return response()->json([
            'granted' => Consent::hasActive(ConsentSubjectType::User, $user->id, ConsentType::PublicDirectory),
        ]);
    }
}
```

- [ ] **Step 5: Wire the route**

In `routes/api/v1/member.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Member\PublicDirectoryConsentController;
```

and add:

```php
Route::patch('consents/public-directory', [PublicDirectoryConsentController::class, 'update']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Member/PublicDirectoryConsentControllerTest.php`
Expected: PASS (all 4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Identity/Models/Consent.php app/Domain/Identity/Models/User.php app/Http/Requests/Member/UpdatePublicDirectoryConsentRequest.php app/Http/Controllers/Api/V1/Member/PublicDirectoryConsentController.php routes/api/v1/member.php tests/Feature/Member/PublicDirectoryConsentControllerTest.php
git commit -m "feat: let members grant/revoke public-directory consent"
```

---

### Task 5: Public member directory

**Files:**
- Create: `app/Http/Resources/MemberDirectoryResource.php`
- Create: `app/Http/Controllers/Api/V1/Public/MemberDirectoryController.php`
- Modify: `routes/api/v1/public.php` (add 1 route)
- Test: `tests/Feature/Public/MemberDirectoryControllerTest.php`

**Interfaces:**
- Consumes: `User::consents()` (Task 4), `User::personalProfile()`/`professionalProfile()` (Task 1), `App\Http\Controllers\Api\V1\Public\PublicResourceController` (existing).
- Produces: `GET /api/v1/public/member-directory`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Public/MemberDirectoryControllerTest.php

namespace Tests\Feature\Public;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberDirectoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_consenting_member_with_a_profile_appears_in_the_directory(): void
    {
        $member = User::factory()->create(['name' => 'Lina Haddad']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile/personal', ['bio' => 'Founder.'])->assertOk();

        $response = $this->getJson('/api/v1/public/member-directory');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Lina Haddad']);
    }

    public function test_a_member_without_consent_is_excluded_even_with_a_filled_profile(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/profile/personal', ['bio' => 'Founder.'])->assertOk();

        $response = $this->getJson('/api/v1/public/member-directory');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_a_consenting_member_with_no_profile_is_excluded(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();

        $response = $this->getJson('/api/v1/public/member-directory');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_revoking_consent_removes_the_member_from_the_next_read(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile/personal', ['bio' => 'Founder.'])->assertOk();
        $this->getJson('/api/v1/public/member-directory')->assertJsonCount(1, 'data');

        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => false])->assertOk();

        $this->getJson('/api/v1/public/member-directory')->assertJsonCount(0, 'data');
    }

    public function test_response_never_includes_phone_or_email(): void
    {
        $member = User::factory()->create(['phone' => '0912345678', 'email' => 'lina@example.com']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile/personal', ['bio' => 'Founder.'])->assertOk();

        $response = $this->getJson('/api/v1/public/member-directory');

        $response->assertOk();
        $response->assertJsonMissing(['phone' => '0912345678']);
        $response->assertJsonMissing(['email' => 'lina@example.com']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Public/MemberDirectoryControllerTest.php`
Expected: FAIL — route doesn't exist (404).

- [ ] **Step 3: Write the Resource**

```php
<?php
// app/Http/Resources/MemberDirectoryResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never includes phone/email — public_directory consent governs listing
 * visibility, not contact-detail exposure (Unit 2 design, 2026-08-09).
 */
class MemberDirectoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'personal' => $this->personalProfile ? [
                'bio' => $this->personalProfile->bio,
                'city' => $this->personalProfile->city,
                'avatar_url' => $this->personalProfile->avatar_url,
            ] : null,
            'professional' => $this->professionalProfile ? [
                'job_title' => $this->professionalProfile->job_title,
                'company_name' => $this->professionalProfile->company_name,
                'industry' => $this->professionalProfile->industry,
                'linkedin_url' => $this->professionalProfile->linkedin_url,
            ] : null,
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php
// app/Http/Controllers/Api/V1/Public/MemberDirectoryController.php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\User;
use App\Http\Resources\MemberDirectoryResource;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entirely separate from `community_members` — that table can never link
 * to a user (tests/Guards/CommunityMembersNoUserLinkTest.php). This lists
 * real user accounts instead, gated on consent (Unit 2 design, 2026-08-09).
 * No membership-tier gating and no community_categories — both are
 * explicitly out of scope for this listing.
 */
class MemberDirectoryController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return User::class;
    }

    protected function resourceClass(): string
    {
        return MemberDirectoryResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query
            ->with(['personalProfile', 'professionalProfile'])
            ->whereHas('consents', function (Builder $q) {
                $q->where('consent_type', ConsentType::PublicDirectory->value)->whereNull('revoked_at');
            })
            ->where(function (Builder $q) {
                $q->whereHas('personalProfile')->orWhereHas('professionalProfile');
            });
    }
}
```

- [ ] **Step 5: Wire the route**

In `routes/api/v1/public.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Public\MemberDirectoryController;
```

and add:

```php
Route::get('member-directory', [MemberDirectoryController::class, 'index']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Public/MemberDirectoryControllerTest.php`
Expected: PASS (all 5 tests)

- [ ] **Step 7: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS, including `tests/Guards/CommunityMembersNoUserLinkTest.php` (untouched by this feature).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Resources/MemberDirectoryResource.php app/Http/Controllers/Api/V1/Public/MemberDirectoryController.php routes/api/v1/public.php tests/Feature/Public/MemberDirectoryControllerTest.php
git commit -m "feat: add public member directory gated on consent + filled profile"
```
