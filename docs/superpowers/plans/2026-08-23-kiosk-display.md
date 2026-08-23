# Reception Kiosk (Banner, Aggregate Endpoint, Arrival Requests) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add admin-managed banner announcements, one unauthenticated aggregate
`GET /api/v1/public/kiosk` endpoint, and a member "I'm here" arrival-request
flow that reception confirms by calling the *existing*, unmodified check-in /
walk-in endpoints.

**Architecture:** Three additive, unrelated-to-each-other pieces sharing one
theme (the physical reception kiosk screen): `App\Domain\Ecosystem\Announcement`
(content, mirrors `ContactLink`'s two-tier-minus-public-listing shape),
`App\Domain\Booking\ArrivalRequest` (a thin signal that dispatches to
`BookingReceptionController::checkIn()` / `WalkInSessionController::store()`
unchanged, never re-implementing their guards), and `Api\V1\Public\KioskController`
(a plain aggregator with no new authority — every value it returns already
exists or is a new `Setting` row).

**Tech Stack:** Laravel 12, PHPUnit (`php artisan test`), SQLite in-memory
test DB, Laravel Pint (default ruleset).

**Spec:** [`docs/decisions/kiosk-display.md`](../../decisions/kiosk-display.md)
— read in full before touching any task below; this plan argues from it and
does not repeat its "why", only its "what."

## Global Constraints

- No existing file in `BookingReceptionController`, `WalkInSessionController`,
  `BookingApprovalService`, `SessionClosureService`, or any other existing
  Booking domain service/model may be modified by this plan. Every task below
  only *calls* these unchanged.
- `Announcement.type` is a plain `string` column with **no** enum cast and
  **no** `Rule::in` — a new type is a row, never a migration (decision doc,
  "One content type, not three").
- `ArrivalRequest.status` **is** a PHP backed enum cast (`ArrivalRequestStatus`)
  — the closed, known status set is the opposite case from `Announcement.type`.
- Every migration in this plan uses `$table->string(...)` for enum-shaped
  columns, never `->enum(...)` (build plan §A.4, enforced by
  `tests/Guards/NoNewMysqlEnumColumnsTest.php`).
- Update-style admin endpoints (`AnnouncementController::update`,
  `ArrivalRequestController::confirm`/`reject`) return
  `response()->json(['message' => __('api.....')])`, never the resource —
  this repo's standing convention (`CLAUDE.md`). `store()`-style endpoints
  (`AnnouncementController::store`, member `ArrivalRequestController::store`)
  are the documented exception and return the created resource.
- All new lang keys live in `lang/{en,ar}/api.php` under a new `kiosk` group
  (arrival-request messages) or the existing `admin` group
  (`announcement_updated`) — never inlined.
- **Known discrepancy from the task prompt, resolved by reading the actual
  code (flagging per this project's "stop and report a conflict" convention,
  documented here rather than blocking, since a correct design follows from
  it):** `BookingReceptionController::checkIn()` does **not** throw
  `ReceptionActionException` — unlike `checkOut`/`cancel`/`settlePayment`/
  `approve`/`reject`/`extend`, it inlines its guards and returns a
  `JsonResponse` directly (200 on success, 409/422 on failure). This actually
  simplifies Task 6: since `WalkInSessionController::store()` *also* always
  returns a `JsonResponse` (it catches `ReceptionActionException` internally
  and converts it to one), `ArrivalRequestController::confirm()` can treat
  both call sites identically — call the method, inspect
  `$response->getStatusCode()`, and return that exact response unchanged on
  failure. No exception handling is needed in `confirm()` at all.

---

## Task 1: `announcements` table, model, factory

**Files:**
- Create: `database/migrations/2026_08_23_090000_create_announcements_table.php`
- Create: `app/Domain/Ecosystem/Models/Announcement.php`
- Create: `database/factories/AnnouncementFactory.php`
- Test: `tests/Unit/Domain/Ecosystem/AnnouncementModelTest.php`

**Interfaces:**
- Produces: `Announcement` fillable = `type, image_url, link_url, sort_order,
  starts_at, ends_at, is_active`; casts `starts_at`/`ends_at` → `datetime`,
  `is_active` → `boolean` (no cast on `type`). `AnnouncementFactory::news()`,
  `::event()`, `::offer()`, `::upcoming()`, `::expired()` states, consumed by
  Task 2 and Task 8's tests.

- [ ] **Step 1: Write the failing model test**

```php
<?php

namespace Tests\Unit\Domain\Ecosystem;

use App\Domain\Ecosystem\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_announcement_can_be_created_with_each_seeded_type(): void
    {
        $news = Announcement::factory()->news()->create();
        $event = Announcement::factory()->event()->create();
        $offer = Announcement::factory()->offer()->create();

        $this->assertSame('news', $news->type);
        $this->assertSame('event', $event->type);
        $this->assertSame('offer', $offer->type);
    }

    public function test_an_arbitrary_new_type_string_is_accepted_with_no_cast(): void
    {
        $announcement = Announcement::factory()->create(['type' => 'holiday_hours']);

        $this->assertSame('holiday_hours', $announcement->fresh()->type);
    }

    public function test_is_active_and_window_columns_cast_correctly(): void
    {
        $announcement = Announcement::factory()->create([
            'is_active' => true,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addWeek(),
        ]);

        $fresh = $announcement->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->starts_at);
        $this->assertInstanceOf(\Carbon\Carbon::class, $fresh->ends_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Ecosystem/AnnouncementModelTest.php`
Expected: FAIL — class `App\Domain\Ecosystem\Models\Announcement` not found
(migration/model/factory don't exist yet).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed banner content for the reception kiosk
 * (docs/decisions/kiosk-display.md). `type` is a plain open string, not a
 * MySQL ENUM and not a PHP backed enum cast — a new kind of announcement is
 * a row, never a migration, following the exact precedent set by
 * `contact_links.type`. `starts_at`/`ends_at` are both nullable so an
 * announcement can be scheduled ahead of time or run indefinitely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('image_url', 2048);
            $table->string('link_url', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Domain\Ecosystem\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Banner content for the reception kiosk (docs/decisions/kiosk-display.md).
 * `type` is deliberately uncast — a plain open string, same precedent as
 * `ContactLink::type` — so a new announcement kind is a row, never a
 * migration or an enum change. `event` here is a display-only flyer with no
 * relationship to `App\Domain\Experience\Event` — do not add one.
 */
class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'image_url',
        'link_url',
        'sort_order',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Domain\Ecosystem\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['news', 'event', 'offer']),
            'image_url' => fake()->imageUrl(),
            'link_url' => null,
            'sort_order' => 0,
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
        ];
    }

    public function news(): static
    {
        return $this->state(['type' => 'news']);
    }

    public function event(): static
    {
        return $this->state(['type' => 'event']);
    }

    public function offer(): static
    {
        return $this->state(['type' => 'offer']);
    }

    /** Scheduled to start in the future — not yet live. */
    public function upcoming(): static
    {
        return $this->state(fn () => ['starts_at' => now()->addDays(3)]);
    }

    /** Window already closed — no longer live. */
    public function expired(): static
    {
        return $this->state(fn () => ['ends_at' => now()->subDay()]);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Ecosystem/AnnouncementModelTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_23_090000_create_announcements_table.php app/Domain/Ecosystem/Models/Announcement.php database/factories/AnnouncementFactory.php tests/Unit/Domain/Ecosystem/AnnouncementModelTest.php
git commit -m "feat: add announcements table, model, and factory"
```

---

## Task 2: Admin `AnnouncementController` (CRUD) + routes + lang

**Files:**
- Create: `app/Http/Requests/Admin/StoreAnnouncementRequest.php`
- Create: `app/Http/Requests/Admin/UpdateAnnouncementRequest.php`
- Create: `app/Http/Resources/AnnouncementResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/AnnouncementController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Ecosystem/AnnouncementTest.php`

**Interfaces:**
- Consumes: `Announcement` model + `AnnouncementFactory` from Task 1;
  `AdminResourceController` (`app_path` base class, unmodified) —
  `modelClass()`, `resourceClass()`, `hasOrderColumn()`,
  `applyIndexFilters(Builder $query, Request $request)`.
- Produces: `GET/POST/PATCH/DELETE /api/v1/admin/announcements`,
  `AnnouncementResource` (fields: `id, type, image_url, link_url, sort_order,
  starts_at, ends_at, is_active, created_at`) — consumed by no later task
  (Task 8's public aggregate does its own minimal inline mapping, not this
  Resource).

**Deliberate deviation from the Founder/Partner/ContactLink two-tier pattern:**
this task creates only `Admin\AnnouncementController` — there is no
`Public\AnnouncementController`. Public read of announcements happens
exclusively through Task 8's aggregate `KioskController`, per the decision
doc. This is intentional, not an oversight; call it out explicitly in the
PR description so a future reviewer doesn't "fix" it by adding one.

**`hasOrderColumn()` note (verification-pass requirement):**
`AdminResourceController::hasOrderColumn()` orders by a column literally
named `order` when true. `announcements` has `sort_order`, not `order` —
exactly the same situation `ContactLink` already solved: override
`hasOrderColumn()` to `false` and add the ordering through the existing
`applyIndexFilters()` hook instead. This keeps `announcements` consistent
with its nearest sibling (`ContactLink`) rather than renaming the column to
match a base-class default that only some resources use.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_can_create_an_announcement_with_real_defaults(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/announcements', [
            'type' => 'offer',
            'image_url' => 'https://example.com/offer.png',
            'link_url' => 'https://example.com/offer',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $response->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('announcements', [
            'type' => 'offer',
            'sort_order' => 0,
            'is_active' => 1,
        ]);
    }

    public function test_a_new_type_string_is_accepted_without_any_migration_or_allow_list(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/announcements', [
            'type' => 'holiday_hours',
            'image_url' => 'https://example.com/holiday.png',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'holiday_hours');
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/announcements', [
            'type' => 'event',
            'image_url' => 'https://example.com/event.png',
            'starts_at' => now()->addDays(5)->toIso8601String(),
            'ends_at' => now()->addDays(1)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('ends_at');
    }

    public function test_operations_can_list_admin_can_update_and_delete(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $announcement = Announcement::factory()->create(['sort_order' => 0]);

        $this->getJson('/api/v1/admin/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.id', $announcement->id);

        $this->actingAsAdmin();

        $this->withHeader('lang', 'en')
            ->putJson("/api/v1/admin/announcements/{$announcement->id}", [
                'type' => $announcement->type,
                'image_url' => 'https://example.com/new.png',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertExactJson(['message' => 'Announcement updated.']);

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'image_url' => 'https://example.com/new.png',
            'is_active' => 0,
        ]);

        $this->deleteJson("/api/v1/admin/announcements/{$announcement->id}")->assertNoContent();
        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    }

    public function test_admin_index_orders_by_sort_order_not_insertion_order(): void
    {
        $this->actingAsAdmin();

        $second = Announcement::factory()->create(['sort_order' => 2]);
        $first = Announcement::factory()->create(['sort_order' => 1]);

        $response = $this->getJson('/api/v1/admin/announcements');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
    }

    public function test_a_member_cannot_manage_announcements(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/announcements')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ecosystem/AnnouncementTest.php`
Expected: FAIL — route `admin/announcements` not found (404).

- [ ] **Step 3: Write the Form Requests**

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAnnouncementRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:50'],
            'image_url' => ['required', 'string', 'max:2048', 'url'],
            'link_url' => ['nullable', 'string', 'max:2048', 'url'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
```

```php
<?php

namespace App\Http\Requests\Admin;

/**
 * Same shape as creating one — extending instead of copy-pasting the rules.
 */
class UpdateAnnouncementRequest extends StoreAnnouncementRequest {}
```

- [ ] **Step 4: Write the Resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'image_url' => $this->image_url,
            'link_url' => $this->link_url,
            'sort_order' => $this->sort_order,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
```

- [ ] **Step 5: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\Announcement;
use App\Http\Requests\Admin\StoreAnnouncementRequest;
use App\Http\Requests\Admin\UpdateAnnouncementRequest;
use App\Http\Resources\AnnouncementResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Announcement::class;
    }

    protected function resourceClass(): string
    {
        return AnnouncementResource::class;
    }

    /**
     * `announcements` orders by `sort_order`, not the base class's hardcoded
     * `order` column — same precedent as `ContactLinkController`.
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
     * `sort_order`/`is_active` are set explicitly here, not left to the
     * migration's column defaults — same reasoning as
     * `ContactLinkController::store`/`FounderController::store`.
     */
    public function store(StoreAnnouncementRequest $request): AnnouncementResource
    {
        return new AnnouncementResource(Announcement::create(array_merge(
            ['sort_order' => 0, 'is_active' => true],
            $request->validated()
        )));
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $announcement->update($request->validated());

        return response()->json(['message' => __('api.admin.announcement_updated')]);
    }
}
```

- [ ] **Step 6: Register routes**

In `routes/api/v1/admin.php`, add the import near the other `Admin\*`
controller imports:

```php
use App\Http\Controllers\Api\V1\Admin\AnnouncementController;
```

and register the resource next to `contact-links` (same permission tier,
same reasoning — public-facing, admin-managed content):

```php
// Reception kiosk banner content (docs/decisions/kiosk-display.md). Same
// permission tier as Founders/Partners/ContactLinks (no narrower role:admin
// group).
Route::apiResource('announcements', AnnouncementController::class);
```

- [ ] **Step 7: Add lang keys**

In `lang/en/api.php`, inside the existing `'admin' => [...]` array, add:

```php
        'announcement_updated' => 'Announcement updated.',
```

In `lang/ar/api.php`, inside the existing `'admin' => [...]` array, add:

```php
        'announcement_updated' => 'تم تحديث الإعلان.',
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test tests/Feature/Ecosystem/AnnouncementTest.php`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add app/Http/Requests/Admin/StoreAnnouncementRequest.php app/Http/Requests/Admin/UpdateAnnouncementRequest.php app/Http/Resources/AnnouncementResource.php app/Http/Controllers/Api/V1/Admin/AnnouncementController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Ecosystem/AnnouncementTest.php
git commit -m "feat: add admin CRUD for reception kiosk announcements"
```

---

## Task 3: `arrival_requests` table, `ArrivalRequestStatus` enum, model, factory

**Files:**
- Create: `database/migrations/2026_08_23_090100_create_arrival_requests_table.php`
- Create: `app/Domain/Booking/Enums/ArrivalRequestStatus.php`
- Create: `app/Domain/Booking/Models/ArrivalRequest.php`
- Create: `database/factories/ArrivalRequestFactory.php`
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
- Test: `tests/Unit/Domain/Booking/ArrivalRequestModelTest.php`

**Interfaces:**
- Produces: `ArrivalRequest` fillable = `user_id, requested_at,
  matched_booking_id, status, confirmed_by_user_id, confirmed_space_id`;
  relations `user(): BelongsTo`, `matchedBooking(): BelongsTo`,
  `confirmedByUser(): BelongsTo`, `confirmedSpace(): BelongsTo`; cast
  `status` → `ArrivalRequestStatus`. `ArrivalRequestFactory::matched()`,
  `::confirmed()`, `::rejected()`, `::expired()` states — consumed by
  Tasks 4, 5, 6, 7.

- [ ] **Step 1: Write the failing model test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArrivalRequestModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unmatched_arrival_request_can_be_created_with_defaults(): void
    {
        $member = User::factory()->create();

        $request = ArrivalRequest::factory()->create(['user_id' => $member->id]);

        $this->assertSame(ArrivalRequestStatus::Pending, $request->status);
        $this->assertNull($request->matched_booking_id);
        $this->assertTrue($request->user->is($member));
    }

    public function test_a_matched_arrival_request_resolves_its_booking_relation(): void
    {
        $booking = Booking::factory()->create();

        $request = ArrivalRequest::factory()->matched()->create();

        $this->assertNotNull($request->matched_booking_id);
        $this->assertInstanceOf(Booking::class, $request->matchedBooking);
    }

    public function test_status_casts_to_a_backed_enum(): void
    {
        $request = ArrivalRequest::factory()->confirmed()->create();

        $this->assertSame(ArrivalRequestStatus::Confirmed, $request->fresh()->status);
        $this->assertNotNull($request->confirmed_by_user_id);
        $this->assertNotNull($request->confirmed_space_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/ArrivalRequestModelTest.php`
Expected: FAIL — class `App\Domain\Booking\Models\ArrivalRequest` not found.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lightweight "I'm here" signal from a member's app after scanning the
 * static kiosk QR (docs/decisions/kiosk-display.md). Never creates a
 * booking or session by itself — reception confirms manually via the
 * existing check-in/walk-in endpoints, recorded here only as
 * confirmed_by_user_id/confirmed_space_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arrival_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->dateTime('requested_at');
            $table->foreignId('matched_booking_id')->nullable()->constrained('bookings');
            $table->string('status', 20)->default('pending');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users');
            $table->foreignId('confirmed_space_id')->nullable()->constrained('spaces');
            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arrival_requests');
    }
};
```

- [ ] **Step 4: Write the enum**

```php
<?php

namespace App\Domain\Booking\Enums;

enum ArrivalRequestStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
```

- [ ] **Step 5: Write the model**

```php
<?php

namespace App\Domain\Booking\Models;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArrivalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'requested_at',
        'matched_booking_id',
        'status',
        'confirmed_by_user_id',
        'confirmed_space_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'status' => ArrivalRequestStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matchedBooking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'matched_booking_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function confirmedSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'confirmed_space_id');
    }
}
```

- [ ] **Step 6: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ArrivalRequest>
 */
class ArrivalRequestFactory extends Factory
{
    protected $model = ArrivalRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'requested_at' => now(),
            'matched_booking_id' => null,
            'status' => ArrivalRequestStatus::Pending,
        ];
    }

    public function matched(): static
    {
        return $this->state(fn () => ['matched_booking_id' => Booking::factory()]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => ArrivalRequestStatus::Confirmed,
            'confirmed_by_user_id' => User::factory(),
            'confirmed_space_id' => Space::factory()->room(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => ArrivalRequestStatus::Rejected]);
    }

    public function expired(): static
    {
        return $this->state(['status' => ArrivalRequestStatus::Expired]);
    }
}
```

- [ ] **Step 7: Add `ArrivalRequest` to the enum-casts guard**

In `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`, add the two
imports next to the other `App\Domain\Booking\*` imports:

```php
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
```

and add an entry to `EXPECTED_CASTS`, next to the existing `Booking::class`
entry:

```php
        ArrivalRequest::class => [
            'status' => ArrivalRequestStatus::class,
        ],
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Domain/Booking/ArrivalRequestModelTest.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: PASS

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_23_090100_create_arrival_requests_table.php app/Domain/Booking/Enums/ArrivalRequestStatus.php app/Domain/Booking/Models/ArrivalRequest.php database/factories/ArrivalRequestFactory.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Unit/Domain/Booking/ArrivalRequestModelTest.php
git commit -m "feat: add arrival_requests table, status enum, model, and factory"
```

---

## Task 4: `ArrivalRequestMatcher` service

**Files:**
- Create: `app/Domain/Booking/Services/ArrivalRequestMatcher.php`
- Test: `tests/Unit/Domain/Booking/ArrivalRequestBookingMatchTest.php`

**Interfaces:**
- Consumes: `Booking`, `BookingStatus` (unchanged, existing), `Branch`
  (existing), `SettingService::get()` (existing, for `app.timezone`).
- Produces: `ArrivalRequestMatcher::matchBookingFor(User $member, Branch
  $branch, CarbonInterface $now): ?Booking` — consumed by Task 5's
  `Member\ArrivalRequestController::store()`.

**Why a separate small class, not inlined in the controller:** the task's
own Unit test (`ArrivalRequestBookingMatchTest`) needs to exercise the
matching query in isolation from HTTP, and a `SettingService`-timezone-aware
day boundary is exactly the kind of logic worth unit-testing directly rather
than only through a feature test.

**Timezone note:** `Booking::start_at` is always stored normalized to UTC
(see `BookingCreationService::create()`'s comment on the same point). "Today"
must therefore be resolved in the branch's local timezone
(`app.timezone` Setting, default `Asia/Damascus`) and converted to a UTC
range before querying — a plain `whereDate('start_at', ...)` would compare
against the wrong calendar day for a booking near local midnight.

- [ ] **Step 1: Write the failing unit test**

```php
<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\ArrivalRequestMatcher;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArrivalRequestBookingMatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_matches_a_confirmed_booking_starting_today_for_the_member(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => \App\Domain\Foundation\Models\Space::factory()->room()->for(
                \App\Domain\Foundation\Models\Building::factory()->for($branch)
            ),
            'start_at' => Carbon::parse('2026-08-23 14:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 15:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher(app(\App\Domain\Settings\Services\SettingService::class)))
            ->matchBookingFor($member, $branch, now());

        $this->assertNotNull($matched);
        $this->assertTrue($matched->is($booking));
    }

    public function test_does_not_match_yesterdays_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => \App\Domain\Foundation\Models\Space::factory()->room()->for(
                \App\Domain\Foundation\Models\Building::factory()->for($branch)
            ),
            'start_at' => Carbon::parse('2026-08-22 14:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-22 15:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher(app(\App\Domain\Settings\Services\SettingService::class)))
            ->matchBookingFor($member, $branch, now());

        $this->assertNull($matched);
    }

    public function test_does_not_match_an_already_checked_in_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        Booking::factory()->checkedIn()->create([
            'user_id' => $member->id,
            'space_id' => \App\Domain\Foundation\Models\Space::factory()->room()->for(
                \App\Domain\Foundation\Models\Building::factory()->for($branch)
            ),
            'start_at' => Carbon::parse('2026-08-23 08:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 09:30:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher(app(\App\Domain\Settings\Services\SettingService::class)))
            ->matchBookingFor($member, $branch, now());

        $this->assertNull($matched);
    }

    public function test_does_not_match_a_rejected_or_cancelled_booking_but_matches_pending(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        $building = \App\Domain\Foundation\Models\Building::factory()->for($branch)->create();

        Booking::factory()->cancelled()->create([
            'user_id' => $member->id,
            'space_id' => \App\Domain\Foundation\Models\Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 10:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 11:00:00', 'Asia/Damascus'),
        ]);
        Booking::factory()->rejected()->create([
            'user_id' => $member->id,
            'space_id' => \App\Domain\Foundation\Models\Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 12:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 13:00:00', 'Asia/Damascus'),
        ]);
        $pending = Booking::factory()->pending()->create([
            'user_id' => $member->id,
            'space_id' => \App\Domain\Foundation\Models\Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 14:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 15:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher(app(\App\Domain\Settings\Services\SettingService::class)))
            ->matchBookingFor($member, $branch, now());

        $this->assertNotNull($matched);
        $this->assertTrue($matched->is($pending));
        $this->assertSame(BookingStatus::Pending, $matched->status);
    }

    public function test_picks_the_soonest_starting_booking_when_multiple_match(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
        $member = User::factory()->create();
        $branch = Branch::factory()->create();
        $building = \App\Domain\Foundation\Models\Building::factory()->for($branch)->create();

        $later = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => \App\Domain\Foundation\Models\Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 16:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 17:00:00', 'Asia/Damascus'),
        ]);
        $sooner = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => \App\Domain\Foundation\Models\Space::factory()->room()->for($building),
            'start_at' => Carbon::parse('2026-08-23 11:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 12:00:00', 'Asia/Damascus'),
        ]);

        $matched = (new ArrivalRequestMatcher(app(\App\Domain\Settings\Services\SettingService::class)))
            ->matchBookingFor($member, $branch, now());

        $this->assertTrue($matched->is($sooner));
        $this->assertFalse($matched->is($later));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Booking/ArrivalRequestBookingMatchTest.php`
Expected: FAIL — class `App\Domain\Booking\Services\ArrivalRequestMatcher`
not found.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;

/**
 * Matches an arrival request to the member's own booking for "today," where
 * "today" is resolved in the branch's local timezone
 * (docs/decisions/kiosk-display.md). This match is informational only — it
 * decides nothing on its own; reception's confirm action is the only place
 * that changes booking/session state.
 */
class ArrivalRequestMatcher
{
    public function __construct(private readonly SettingService $settings) {}

    public function matchBookingFor(User $member, Branch $branch, CarbonInterface $now): ?Booking
    {
        $timezone = $this->settings->get('app.timezone', 'Asia/Damascus');
        $localNow = $now->copy()->setTimezone($timezone);
        $startOfDayUtc = $localNow->copy()->startOfDay()->setTimezone('UTC');
        $endOfDayUtc = $localNow->copy()->endOfDay()->setTimezone('UTC');

        return Booking::query()
            ->where('user_id', $member->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->whereNull('checked_in_at')
            ->whereBetween('start_at', [$startOfDayUtc, $endOfDayUtc])
            ->whereHas('space.building', fn ($query) => $query->where('branch_id', $branch->id))
            ->orderBy('start_at')
            ->first();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Booking/ArrivalRequestBookingMatchTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Services/ArrivalRequestMatcher.php tests/Unit/Domain/Booking/ArrivalRequestBookingMatchTest.php
git commit -m "feat: add ArrivalRequestMatcher for today's-booking matching"
```

---

## Task 5: Member `ArrivalRequestController::store()` + route + resource

**Files:**
- Create: `app/Http/Resources/ArrivalRequestResource.php`
- Create: `app/Http/Controllers/Api/V1/Member/ArrivalRequestController.php`
- Modify: `routes/api/v1/member.php`
- Test: `tests/Feature/Booking/ArrivalRequestTest.php` (new file — this task
  writes its first section; Task 6 and Task 7 append to it)

**Interfaces:**
- Consumes: `ArrivalRequestMatcher::matchBookingFor()` (Task 4),
  `ArrivalRequest` model + factory (Task 3).
- Produces: `POST /api/v1/member/arrival-requests` → `ArrivalRequestResource`;
  `ArrivalRequestResource` fields `id, status, requested_at,
  matched_booking_id`, plus `user`/`matched_booking` conditionally via
  `whenLoaded()` — consumed by Task 6's admin `index()`.

- [ ] **Step 1: Write the failing feature test (member store section)**

```php
<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ArrivalRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-23 09:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function spaceInBranch(Branch $branch): Space
    {
        $building = \App\Domain\Foundation\Models\Building::factory()->for($branch)->create();

        return Space::factory()->room()->for($building)->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
    }

    public function test_creating_an_arrival_request_matches_todays_confirmed_booking(): void
    {
        $branch = Branch::factory()->create();
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->spaceInBranch($branch)->id,
            'start_at' => Carbon::parse('2026-08-23 14:00:00', 'Asia/Damascus'),
            'end_at' => Carbon::parse('2026-08-23 15:00:00', 'Asia/Damascus'),
        ]);

        $response = $this->postJson('/api/v1/member/arrival-requests');

        $response->assertOk();
        $response->assertJsonPath('data.matched_booking_id', $booking->id);
        $this->assertSame(1, ArrivalRequest::where('user_id', $member->id)->count());
    }

    public function test_creating_an_arrival_request_with_no_matching_booking_leaves_it_unmatched(): void
    {
        Branch::factory()->create();
        $this->actingAsMember();

        $response = $this->postJson('/api/v1/member/arrival-requests');

        $response->assertOk();
        $response->assertJsonPath('data.matched_booking_id', null);
    }

    public function test_a_non_member_cannot_create_an_arrival_request(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/member/arrival-requests')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/ArrivalRequestTest.php`
Expected: FAIL — route `member/arrival-requests` not found (404).

- [ ] **Step 3: Write the Resource**

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArrivalRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'requested_at' => $this->requested_at,
            'matched_booking_id' => $this->matched_booking_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ]),
            'matched_booking' => $this->whenLoaded('matchedBooking', fn () => $this->matchedBooking === null ? null : [
                'id' => $this->matchedBooking->id,
                'space_id' => $this->matchedBooking->space_id,
                'start_at' => $this->matchedBooking->start_at,
                'end_at' => $this->matchedBooking->end_at,
            ]),
        ];
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Services\ArrivalRequestMatcher;
use App\Domain\Foundation\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArrivalRequestResource;
use Illuminate\Http\Request;

class ArrivalRequestController extends Controller
{
    public function store(Request $request, ArrivalRequestMatcher $matcher): ArrivalRequestResource
    {
        $member = $request->user();
        $branch = Branch::query()->where('is_active', true)->first();
        $matchedBooking = $branch === null ? null : $matcher->matchBookingFor($member, $branch, now());

        $arrivalRequest = ArrivalRequest::create([
            'user_id' => $member->id,
            'requested_at' => now(),
            'matched_booking_id' => $matchedBooking?->id,
        ]);

        return new ArrivalRequestResource($arrivalRequest);
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api/v1/member.php`, add the import next to the other
`Member\*` controller imports:

```php
use App\Http\Controllers\Api\V1\Member\ArrivalRequestController;
```

and register the route (near the booking routes, since it feeds the same
domain):

```php
// Reception kiosk "I'm here" signal (docs/decisions/kiosk-display.md) —
// never changes booking/session state by itself.
Route::post('arrival-requests', [ArrivalRequestController::class, 'store']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Booking/ArrivalRequestTest.php`
Expected: PASS (all 3 tests written so far)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Resources/ArrivalRequestResource.php app/Http/Controllers/Api/V1/Member/ArrivalRequestController.php routes/api/v1/member.php tests/Feature/Booking/ArrivalRequestTest.php
git commit -m "feat: add member arrival-request creation endpoint"
```

---

## Task 6: Admin Reception `ArrivalRequestController` (index/confirm/reject)

**Files:**
- Create: `app/Http/Controllers/Api/V1/Admin/Reception/ArrivalRequestController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Modify: `tests/Feature/Booking/ArrivalRequestTest.php` (append confirm/reject
  coverage to the file Task 5 created)

**Interfaces:**
- Consumes (unmodified, called directly — no re-implementation):
  `App\Http\Controllers\Api\V1\Admin\Reception\BookingReceptionController::checkIn(Booking $booking, BusinessHoursService $businessHours): JsonResponse`;
  `App\Http\Controllers\Api\V1\Admin\Reception\WalkInSessionController::store(StoreWalkInSessionRequest $request, WalkInCapacityService $capacity): JsonResponse`;
  `App\Http\Requests\Admin\Reception\StoreWalkInSessionRequest` (rules:
  `space_id` required|integer|exists:spaces,id, `user_id`
  required|integer|exists:users,id; `authorize()` returns `true`).
- Produces: `GET /api/v1/admin/reception/arrival-requests` (paginated,
  `ArrivalRequestResource::collection`), `POST
  .../{arrivalRequest}/confirm`, `POST .../{arrivalRequest}/reject`.

**How `confirm()` calls the existing endpoints unchanged (verification-pass
requirement — call sites, pasted here so this is checkable without re-reading
the whole diff):**

```php
$response = app(BookingReceptionController::class)->checkIn(
    $arrivalRequest->matchedBooking,
    app(BusinessHoursService::class)
);
```

and, for the unmatched path:

```php
$response = app(WalkInSessionController::class)->store($walkInRequest, app(WalkInCapacityService::class));
```

where `$walkInRequest` is a real `StoreWalkInSessionRequest`, built and
validated the same way the framework itself builds and validates any
`FormRequest` — `Request::create()` (public Symfony API) populates the
input, then `setContainer()`/`setRedirector()`/`validateResolved()` (public
`FormRequest` methods) run its `authorize()`+`rules()` exactly as they would
during normal HTTP dispatch. Both call sites always return a `JsonResponse`
(`checkIn()` inlines its guards into one; `store()` catches
`ReceptionActionException` internally and converts it to one) — `confirm()`
never needs to know which mechanism produced it, it just inspects
`getStatusCode()`.

- [ ] **Step 1: Write the failing feature test (append to `ArrivalRequestTest.php`)**

Add these methods inside the existing `ArrivalRequestTest` class (before
its closing `}`):

```php
    public function test_operations_can_list_pending_arrival_requests(): void
    {
        $this->actingAsOperations();
        $branch = Branch::factory()->create();
        $pending = ArrivalRequest::factory()->create(['requested_at' => now()->subMinutes(5)]);
        ArrivalRequest::factory()->confirmed()->create();

        $response = $this->getJson('/api/v1/admin/reception/arrival-requests');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $pending->id);
    }

    public function test_confirming_a_matched_arrival_request_checks_in_the_booking(): void
    {
        $operator = $this->actingAsOperations();
        $branch = Branch::factory()->create();
        $space = $this->spaceInBranch($branch);
        \App\Domain\Foundation\Models\BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => \App\Domain\Foundation\Enums\DayOfWeek::Sunday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
        $booking = Booking::factory()->create(['space_id' => $space->id]);
        $arrivalRequest = ArrivalRequest::factory()->create(['matched_booking_id' => $booking->id]);

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm");

        $response->assertOk();
        $this->assertNotNull($booking->fresh()->checked_in_at);
        $arrivalRequest->refresh();
        $this->assertSame(ArrivalRequestStatus::Confirmed, $arrivalRequest->status);
        $this->assertSame($operator->id, $arrivalRequest->confirmed_by_user_id);
        $this->assertSame($space->id, $arrivalRequest->confirmed_space_id);
    }

    public function test_confirming_a_matched_request_still_enforces_check_in_guards(): void
    {
        $this->actingAsOperations();
        $branch = Branch::factory()->create();
        $space = $this->spaceInBranch($branch);
        $booking = Booking::factory()->checkedIn()->create(['space_id' => $space->id]);
        $arrivalRequest = ArrivalRequest::factory()->create(['matched_booking_id' => $booking->id]);

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm");

        $response->assertStatus(409)->assertExactJson(['message' => 'This booking has already been checked in.']);
        $this->assertSame(ArrivalRequestStatus::Pending, $arrivalRequest->fresh()->status);
    }

    public function test_confirming_an_unmatched_request_without_space_id_is_rejected(): void
    {
        $this->actingAsOperations();
        $arrivalRequest = ArrivalRequest::factory()->create();

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm");

        $response->assertStatus(422);
        $this->assertSame(ArrivalRequestStatus::Pending, $arrivalRequest->fresh()->status);
    }

    public function test_confirming_an_unmatched_request_with_space_id_creates_a_walk_in_session(): void
    {
        $operator = $this->actingAsOperations();
        $branch = Branch::factory()->create();
        $space = $this->spaceInBranch($branch);
        \App\Domain\Foundation\Models\BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => \App\Domain\Foundation\Enums\DayOfWeek::Sunday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
        $arrivalRequest = ArrivalRequest::factory()->create();

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm", [
            'space_id' => $space->id,
        ]);

        $response->assertOk();
        $this->assertSame(1, \App\Domain\Booking\Models\WalkinSession::where('space_id', $space->id)->where('user_id', $arrivalRequest->user_id)->count());
        $arrivalRequest->refresh();
        $this->assertSame(ArrivalRequestStatus::Confirmed, $arrivalRequest->status);
        $this->assertSame($operator->id, $arrivalRequest->confirmed_by_user_id);
        $this->assertSame($space->id, $arrivalRequest->confirmed_space_id);
    }

    public function test_confirming_a_non_pending_request_is_conflict(): void
    {
        $this->actingAsOperations();
        $arrivalRequest = ArrivalRequest::factory()->rejected()->create();

        $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm")
            ->assertStatus(409);
    }

    public function test_operations_can_reject_a_pending_arrival_request(): void
    {
        $this->actingAsOperations();
        $arrivalRequest = ArrivalRequest::factory()->create();

        $response = $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/reject");

        $response->assertOk();
        $this->assertSame(ArrivalRequestStatus::Rejected, $arrivalRequest->fresh()->status);
    }

    public function test_rejecting_a_non_pending_request_is_conflict(): void
    {
        $this->actingAsOperations();
        $arrivalRequest = ArrivalRequest::factory()->confirmed()->create();

        $this->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/reject")
            ->assertStatus(409);
    }
```

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `php artisan test tests/Feature/Booking/ArrivalRequestTest.php`
Expected: FAIL — routes not found (404) for the new endpoints.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreWalkInSessionRequest;
use App\Http\Resources\ArrivalRequestResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Redirector;

class ArrivalRequestController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        $requests = ArrivalRequest::query()
            ->where('status', ArrivalRequestStatus::Pending)
            ->orderBy('requested_at')
            ->with(['user', 'matchedBooking.space'])
            ->paginate(25);

        return ArrivalRequestResource::collection($requests);
    }

    public function confirm(Request $request, ArrivalRequest $arrivalRequest): JsonResponse
    {
        if ($arrivalRequest->status !== ArrivalRequestStatus::Pending) {
            return response()->json(['message' => __('api.kiosk.arrival_request_not_pending')], 409);
        }

        if ($arrivalRequest->matched_booking_id !== null) {
            $response = app(BookingReceptionController::class)->checkIn(
                $arrivalRequest->matchedBooking,
                app(BusinessHoursService::class)
            );

            if ($response->getStatusCode() >= 400) {
                return $response;
            }

            $confirmedSpaceId = $arrivalRequest->matchedBooking->space_id;
        } else {
            if (! $request->filled('space_id')) {
                return response()->json(['message' => __('api.kiosk.space_id_required')], 422);
            }

            $walkInRequest = StoreWalkInSessionRequest::create('/', 'POST', [
                'space_id' => $request->input('space_id'),
                'user_id' => $arrivalRequest->user_id,
            ]);
            $walkInRequest->setContainer(app());
            $walkInRequest->setRedirector(app(Redirector::class));
            $walkInRequest->validateResolved();

            $response = app(WalkInSessionController::class)->store($walkInRequest, app(WalkInCapacityService::class));

            if ($response->getStatusCode() >= 400) {
                return $response;
            }

            $confirmedSpaceId = (int) $request->input('space_id');
        }

        $arrivalRequest->forceFill([
            'status' => ArrivalRequestStatus::Confirmed,
            'confirmed_by_user_id' => $request->user()->id,
            'confirmed_space_id' => $confirmedSpaceId,
        ])->save();

        $this->logSensitiveAction('arrival_request_confirmed', $arrivalRequest);

        return response()->json(['message' => __('api.kiosk.arrival_request_confirmed')]);
    }

    public function reject(ArrivalRequest $arrivalRequest): JsonResponse
    {
        if ($arrivalRequest->status !== ArrivalRequestStatus::Pending) {
            return response()->json(['message' => __('api.kiosk.arrival_request_not_pending')], 409);
        }

        $arrivalRequest->forceFill(['status' => ArrivalRequestStatus::Rejected])->save();

        $this->logSensitiveAction('arrival_request_rejected', $arrivalRequest);

        return response()->json(['message' => __('api.kiosk.arrival_request_rejected')]);
    }
}
```

- [ ] **Step 4: Register the routes**

In `routes/api/v1/admin.php`, add the import next to the other
`Admin\Reception\*` imports:

```php
use App\Http\Controllers\Api\V1\Admin\Reception\ArrivalRequestController as ReceptionArrivalRequestController;
```

and register the routes next to the other `reception/*` routes:

```php
Route::get('reception/arrival-requests', [ReceptionArrivalRequestController::class, 'index']);
Route::post('reception/arrival-requests/{arrivalRequest}/confirm', [ReceptionArrivalRequestController::class, 'confirm']);
Route::post('reception/arrival-requests/{arrivalRequest}/reject', [ReceptionArrivalRequestController::class, 'reject']);
```

(The `as ReceptionArrivalRequestController` alias avoids a name collision in
this file's import list — there is no other `ArrivalRequestController`
imported into `admin.php`, but the alias makes the distinction from the
`Member\ArrivalRequestController` used in `member.php` explicit at the call
site.)

- [ ] **Step 5: Add lang keys**

In `lang/en/api.php`, add a new top-level `'kiosk' => [...]` group (placed
after the existing `'booking' => [...]` group):

```php
    'kiosk' => [
        'arrival_request_not_pending' => 'This arrival request is no longer pending.',
        'space_id_required' => 'A space is required to confirm an unmatched arrival request.',
        'arrival_request_confirmed' => 'Arrival confirmed.',
        'arrival_request_rejected' => 'Arrival request rejected.',
    ],
```

In `lang/ar/api.php`, add the matching group in the same position:

```php
    'kiosk' => [
        'arrival_request_not_pending' => 'طلب الوصول هذا لم يعد قيد الانتظار.',
        'space_id_required' => 'يجب تحديد مساحة لتأكيد طلب وصول غير مطابق لحجز.',
        'arrival_request_confirmed' => 'تم تأكيد الوصول.',
        'arrival_request_rejected' => 'تم رفض طلب الوصول.',
    ],
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Booking/ArrivalRequestTest.php`
Expected: PASS (all tests in the file)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/Admin/Reception/ArrivalRequestController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Booking/ArrivalRequestTest.php
git commit -m "feat: add reception confirm/reject for arrival requests"
```

---

## Task 7: `ExpireStaleArrivalRequests` scheduled command + expiry setting

**Files:**
- Create: `app/Console/Commands/ExpireStaleArrivalRequests.php`
- Modify: `database/seeders/SettingSeeder.php`
- Modify: `routes/console.php`
- Modify: `tests/Feature/Booking/ArrivalRequestTest.php` (append expiry
  coverage)

**Interfaces:**
- Consumes: `SettingService::get()` (existing), `ArrivalRequest`/
  `ArrivalRequestStatus` (Task 3).
- Produces: `php artisan kiosk:expire-stale-arrival-requests`, `Setting`
  key `kiosk.arrival_request_expiry_minutes` (default `30`).

- [ ] **Step 1: Write the failing feature test (append to `ArrivalRequestTest.php`)**

Add these methods inside the existing `ArrivalRequestTest` class:

```php
    public function test_the_expiry_sweep_only_marks_pending_requests_past_the_window(): void
    {
        $stale = ArrivalRequest::factory()->create(['requested_at' => now()->subMinutes(45)]);
        $fresh = ArrivalRequest::factory()->create(['requested_at' => now()->subMinutes(5)]);
        $alreadyConfirmed = ArrivalRequest::factory()->confirmed()->create(['requested_at' => now()->subMinutes(45)]);

        $this->artisan('kiosk:expire-stale-arrival-requests')->assertExitCode(0);

        $this->assertSame(ArrivalRequestStatus::Expired, $stale->fresh()->status);
        $this->assertSame(ArrivalRequestStatus::Pending, $fresh->fresh()->status);
        $this->assertSame(ArrivalRequestStatus::Confirmed, $alreadyConfirmed->fresh()->status);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Booking/ArrivalRequestTest.php --filter=test_the_expiry_sweep_only_marks_pending_requests_past_the_window`
Expected: FAIL — command `kiosk:expire-stale-arrival-requests` does not
exist.

- [ ] **Step 3: Seed the expiry-window setting**

In `database/seeders/SettingSeeder.php`, add a line inside `run()`, after
the existing `guest.host_approval_timeout_seconds` line:

```php
        $settings->setDefault('kiosk.arrival_request_expiry_minutes', 30, SettingValueType::Int);
```

- [ ] **Step 4: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Console\Command;

/**
 * Mirrors CloseOverdueReceptionSessions's pattern: a pending arrival request
 * older than the configured window almost always means the member scanned
 * and then left without reception acting on it — this keeps the reception
 * queue from accumulating stale entries (docs/decisions/kiosk-display.md).
 */
class ExpireStaleArrivalRequests extends Command
{
    protected $signature = 'kiosk:expire-stale-arrival-requests';

    protected $description = 'Mark pending arrival requests older than the configured window as expired.';

    public function handle(SettingService $settings): int
    {
        $minutes = (int) $settings->get('kiosk.arrival_request_expiry_minutes', 30);

        ArrivalRequest::query()
            ->where('status', ArrivalRequestStatus::Pending)
            ->where('requested_at', '<', now()->subMinutes($minutes))
            ->update(['status' => ArrivalRequestStatus::Expired->value]);

        return self::SUCCESS;
    }
}
```

Note the explicit `->value` on the bulk `update()` call: a query-builder
mass update does not run Eloquent's attribute casting, so the raw enum
*value* must be passed, not the enum case itself.

- [ ] **Step 5: Register the schedule**

In `routes/console.php`, add after the existing
`reception:close-overdue-sessions` schedule entry:

```php
/*
 * Reception kiosk (docs/decisions/kiosk-display.md) — a pending arrival
 * request older than its configured window is stale, not actionable; sweep
 * it to `expired` so reception's queue doesn't accumulate entries from
 * members who scanned and then left.
 */
Schedule::command('kiosk:expire-stale-arrival-requests')->everyFiveMinutes()->withoutOverlapping();
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Booking/ArrivalRequestTest.php`
Expected: PASS (all tests in the file)

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ExpireStaleArrivalRequests.php database/seeders/SettingSeeder.php routes/console.php tests/Feature/Booking/ArrivalRequestTest.php
git commit -m "feat: add scheduled sweep to expire stale arrival requests"
```

---

## Task 8: Kiosk settings seeds + `KioskController` aggregate endpoint

**Files:**
- Modify: `database/seeders/SettingSeeder.php`
- Create: `app/Http/Controllers/Api/V1/Public/KioskController.php`
- Modify: `routes/api/v1/public.php`
- Test: `tests/Feature/Public/KioskControllerTest.php`

**Interfaces:**
- Consumes: `Announcement` (Task 1), `App\Domain\Membership\Models\Plan`
  (existing, unmodified), `App\Domain\Ecosystem\Models\ContactLink`
  (existing, unmodified), `SettingService::get()` (existing).
- Produces: `GET /api/v1/public/kiosk` → the exact sectioned shape from
  the decision doc (`banner.{news,events,offers,plans}`, `social_links`,
  `app_download.url`, `arrival_qr.value`).

**Why no shared `Resource` class for the banner/social sections:** the
decision doc's response sample uses a narrower field set than the existing
`AnnouncementResource`/`PlanResource`/`ContactLinkResource` (e.g.
`social_links` omits `id`/`sort_order`/`is_visible`; `banner.plans` omits
`is_subscription`/`overage_rate`/`order`/`created_at` and — deliberately —
the currency-conversion side effect `PlanResource` triggers via
`CurrencyResolver`, which is not part of this endpoint's contract). A plain
inline mapping keeps the shape locked to exactly what the doc specifies.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature\Public;

use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Membership\Models\Plan;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_endpoint_requires_no_authentication_and_has_every_section(): void
    {
        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonStructure([
            'banner' => ['news', 'events', 'offers', 'plans'],
            'social_links',
            'app_download' => ['url'],
            'arrival_qr' => ['value'],
        ]);
        $response->assertJsonPath('banner.news', []);
        $response->assertJsonPath('banner.events', []);
        $response->assertJsonPath('banner.offers', []);
        $response->assertJsonPath('social_links', []);
    }

    public function test_banner_sections_respect_type_and_live_window_independently(): void
    {
        $liveNews = Announcement::factory()->news()->create(['sort_order' => 1]);
        Announcement::factory()->news()->create(['is_active' => false]);
        Announcement::factory()->news()->upcoming()->create();
        Announcement::factory()->news()->expired()->create();
        $liveOffer = Announcement::factory()->offer()->create();
        Announcement::factory()->event()->upcoming()->create();

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'banner.news');
        $response->assertJsonPath('banner.news.0.id', $liveNews->id);
        $response->assertJsonPath('banner.news.0.image_url', $liveNews->image_url);
        $response->assertJsonCount(1, 'banner.offers');
        $response->assertJsonPath('banner.offers.0.id', $liveOffer->id);
        $response->assertJsonCount(0, 'banner.events');
    }

    public function test_banner_plans_reads_the_existing_active_plan_catalog(): void
    {
        $plan = Plan::factory()->create(['is_active' => true, 'order' => 1]);
        Plan::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'banner.plans');
        $response->assertJsonPath('banner.plans.0.id', $plan->id);
        $response->assertJsonPath('banner.plans.0.pricing_currency', $plan->pricing_currency);
    }

    public function test_social_links_only_returns_visible_links_in_the_minimal_shape(): void
    {
        ContactLink::create(['type' => 'phone', 'value' => '+963000000', 'sort_order' => 0, 'is_visible' => false]);
        $visible = ContactLink::create(['type' => 'instagram', 'value' => 'https://instagram.com/add', 'label' => 'Instagram', 'sort_order' => 1, 'is_visible' => true]);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonCount(1, 'social_links');
        $response->assertJsonPath('social_links.0', [
            'type' => $visible->type,
            'value' => $visible->value,
            'label' => $visible->label,
        ]);
    }

    public function test_app_download_and_arrival_qr_read_from_settings(): void
    {
        app(SettingService::class)->set('kiosk.app_download_url', 'https://apps.example.com/add', SettingValueType::String);
        app(SettingService::class)->set('kiosk.arrival_qr_value', 'addapp://arrival/kiosk-1', SettingValueType::String);

        $response = $this->getJson('/api/v1/public/kiosk');

        $response->assertOk();
        $response->assertJsonPath('app_download.url', 'https://apps.example.com/add');
        $response->assertJsonPath('arrival_qr.value', 'addapp://arrival/kiosk-1');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Public/KioskControllerTest.php`
Expected: FAIL — route `public/kiosk` not found (404).

- [ ] **Step 3: Seed the two kiosk settings**

In `database/seeders/SettingSeeder.php`, add these two lines after the
`kiosk.arrival_request_expiry_minutes` line added in Task 7 (both are
placeholders — flagged below for Maryam to fill in the real values before
launch, same convention `settings-key-value-store.md` already uses for its
own placeholder defaults):

```php
        $settings->setDefault('kiosk.app_download_url', 'https://example.com/download', SettingValueType::String);
        $settings->setDefault('kiosk.arrival_qr_value', 'addapp://arrival', SettingValueType::String);
```

- [ ] **Step 4: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Membership\Models\Plan;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * One unauthenticated aggregate for the reception kiosk screen
 * (docs/decisions/kiosk-display.md). Deliberately does not extend
 * PublicResourceController — it aggregates four independent sources into a
 * sectioned shape rather than listing one resource. Every section's field
 * set is locked to the decision doc's sample response, which is narrower
 * than this app's general-purpose Resources for the same models (e.g. no
 * currency-conversion side effect from PlanResource) — so this builds plain
 * arrays instead of reusing them.
 */
class KioskController extends Controller
{
    public function show(SettingService $settings): JsonResponse
    {
        return response()->json([
            'banner' => [
                'news' => $this->liveAnnouncementsOfType('news'),
                'events' => $this->liveAnnouncementsOfType('event'),
                'offers' => $this->liveAnnouncementsOfType('offer'),
                'plans' => $this->activePlans(),
            ],
            'social_links' => $this->visibleSocialLinks(),
            'app_download' => ['url' => $settings->get('kiosk.app_download_url')],
            'arrival_qr' => ['value' => $settings->get('kiosk.arrival_qr_value')],
        ]);
    }

    /**
     * @return array<int, array{id: int, image_url: string, link_url: ?string}>
     */
    private function liveAnnouncementsOfType(string $type): array
    {
        $now = now();

        return Announcement::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'image_url' => $announcement->image_url,
                'link_url' => $announcement->link_url,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activePlans(): array
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'price' => (string) $plan->price,
                'pricing_currency' => $plan->pricing_currency,
                'duration_days' => $plan->duration_days,
                'included_hours' => (string) $plan->included_hours,
            ])
            ->all();
    }

    /**
     * @return array<int, array{type: string, value: string, label: ?string}>
     */
    private function visibleSocialLinks(): array
    {
        return ContactLink::query()
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ContactLink $link) => [
                'type' => $link->type,
                'value' => $link->value,
                'label' => $link->label,
            ])
            ->all();
    }
}
```

- [ ] **Step 5: Register the route**

In `routes/api/v1/public.php`, add the import next to the other
`Public\*` controller imports:

```php
use App\Http\Controllers\Api\V1\Public\KioskController;
```

and register the route:

```php
Route::get('kiosk', [KioskController::class, 'show']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Public/KioskControllerTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/seeders/SettingSeeder.php app/Http/Controllers/Api/V1/Public/KioskController.php routes/api/v1/public.php tests/Feature/Public/KioskControllerTest.php
git commit -m "feat: add public kiosk aggregate endpoint"
```

---

## Task 9: Full verification pass

**Files:** none created — this task only runs and reads output.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: All tests pass, including every file created/modified in Tasks
1–8 and the pre-existing suite (in particular
`tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`,
`tests/Guards/NoNewMysqlEnumColumnsTest.php`,
`tests/Guards/DomainLayerBoundaryTest.php`).

- [ ] **Step 2: Run Pint on every file touched or created**

```bash
./vendor/bin/pint --test app/Domain/Ecosystem/Models/Announcement.php app/Domain/Booking/Models/ArrivalRequest.php app/Domain/Booking/Enums/ArrivalRequestStatus.php app/Domain/Booking/Services/ArrivalRequestMatcher.php app/Http/Controllers/Api/V1/Admin/AnnouncementController.php app/Http/Controllers/Api/V1/Admin/Reception/ArrivalRequestController.php app/Http/Controllers/Api/V1/Member/ArrivalRequestController.php app/Http/Controllers/Api/V1/Public/KioskController.php app/Http/Requests/Admin/StoreAnnouncementRequest.php app/Http/Requests/Admin/UpdateAnnouncementRequest.php app/Http/Resources/AnnouncementResource.php app/Http/Resources/ArrivalRequestResource.php app/Console/Commands/ExpireStaleArrivalRequests.php database/factories/AnnouncementFactory.php database/factories/ArrivalRequestFactory.php database/seeders/SettingSeeder.php routes/api/v1/admin.php routes/api/v1/public.php routes/api/v1/member.php routes/console.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Unit/Domain/Ecosystem/AnnouncementModelTest.php tests/Unit/Domain/Booking/ArrivalRequestModelTest.php tests/Unit/Domain/Booking/ArrivalRequestBookingMatchTest.php tests/Feature/Ecosystem/AnnouncementTest.php tests/Feature/Booking/ArrivalRequestTest.php tests/Feature/Public/KioskControllerTest.php
```

If Pint reports any file needing changes, run the same command without
`--test` to auto-fix, then re-run the full test suite.

- [ ] **Step 3: Confirm no existing file was modified outside this plan's list**

```bash
git status
git diff --stat
```

Confirm `BookingReceptionController.php`, `WalkInSessionController.php`,
`BookingApprovalService.php`, `SessionClosureService.php`, and every other
pre-existing Booking-domain service/model show **zero** diff.

- [ ] **Step 4: Report on the two flagged open items**

In the final summary to the user, explicitly state:
1. `hasOrderColumn()` verdict — `announcements` keeps `sort_order` (not
   renamed to `order`), following `ContactLink`'s exact precedent, with
   `hasOrderColumn()` overridden to `false` and ordering added via
   `applyIndexFilters()`.
2. `kiosk.app_download_url` and `kiosk.arrival_qr_value` are seeded with
   placeholder values (`https://example.com/download`,
   `addapp://arrival`) and need real values from Maryam before production
   — same as this plan's Global Constraints section already notes.
3. The `checkIn()`-does-not-throw-`ReceptionActionException` discrepancy
   from the task prompt (Global Constraints section) and how `confirm()`'s
   uniform "call it, check `getStatusCode()`" design handles both call
   sites correctly regardless.
4. `Announcement` has no `Public\AnnouncementController` — public read is
   exclusively through `KioskController` (Task 2's deliberate-deviation
   note) — so this isn't mistaken for a missed piece of the two-tier
   pattern.
5. The Postman collection (`postman/ADD-OS.postman_collection.json`) was
   **not** updated — the decision doc's "what this changes in code" list
   mentions a new `Kiosk` folder there, but it wasn't in this plan's task
   breakdown's explicit scope. Flag this as a known gap rather than silently
   skipping it.

- [ ] **Step 5: No commit for this task** (verification only — nothing to
  stage).
