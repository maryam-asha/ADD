# Unified Community Categories Taxonomy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** replace `community_members.category` and `partners.category` (two separate hardcoded enums) with one shared, admin-manageable `community_categories` lookup table, referenced by both via `category_id`.

**Architecture:** a new `CommunityCategory` model (Ecosystem domain) with soft-deactivation (never hard-deleted, to preserve FK integrity). Both `community_members` and `partners` drop their enum column and gain a nullable `category_id` FK to the same table. A new admin CRUD controller manages categories; the public `community-members` listing's existing `?category=` filter is adapted from the raw enum value to the category's `key` slug.

**Tech Stack:** Laravel 12, PHPUnit, SQLite (in-memory, tests).

## Global Constraints

- No transition period — both enums are dropped entirely, not deprecated alongside the new column.
- `community_categories` rows are never hard-deleted — only deactivated (`is_active = false`), to preserve FK integrity for existing `community_members`/`partners` rows.
- Do not seed final category values — schema/migration only, seed data supplied separately.
- "All / view all" aggregate filters are UI-only and must never be persisted as a row in `community_categories`.
- `label` uses the single-column `{ar, en}` JSON translation pattern (`App\Concerns\HasTranslations` / `App\Support\TranslatableField`) — no `_ar`/`_en` column pairs.
- Both `community_members.category_id` and `partners.category_id` must reference the same `community_categories` table — one shared taxonomy, not two.
- Every new feature test seeds roles via `RoleSeeder` and authenticates via `Laravel\Sanctum\Sanctum::actingAs($user, ['*'])`. `community_members`/`partners` have no factories today — tests create rows via their existing admin HTTP endpoints, matching `tests/Feature/Ecosystem/PartnerDefaultsTest.php`/`CommunityMemberDefaultsTest.php`.

---

### Task 1: `community_categories` table + model + factory

**Files:**
- Create: `database/migrations/2026_08_09_160004_create_community_categories_table.php`
- Create: `app/Domain/Ecosystem/Models/CommunityCategory.php`
- Create: `database/factories/CommunityCategoryFactory.php`
- Test: `tests/Unit/Domain/Ecosystem/CommunityCategoryTest.php`

**Interfaces:**
- Produces: `CommunityCategory` model, fillable `key`, `label`, `icon`, `sort_order`, `is_active`, `created_by`; `translate('label', ?string $locale)` (via `HasTranslations`). Factory state `inactive()`. Consumed by Tasks 2–5.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/Domain/Ecosystem/CommunityCategoryTest.php

namespace Tests\Unit\Domain\Ecosystem;

use App\Domain\Ecosystem\Models\CommunityCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_category_can_be_created_with_a_bilingual_label(): void
    {
        $category = CommunityCategory::factory()->create([
            'key' => 'startups',
            'label' => ['ar' => 'شركات ناشئة', 'en' => 'Startups'],
        ]);

        $this->assertDatabaseHas('community_categories', ['key' => 'startups']);
        $this->assertSame('Startups', $category->translate('label', 'en'));
        $this->assertSame('شركات ناشئة', $category->translate('label', 'ar'));
    }

    public function test_the_key_column_is_unique(): void
    {
        CommunityCategory::factory()->create(['key' => 'startups']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        CommunityCategory::factory()->create(['key' => 'startups']);
    }

    public function test_inactive_state_sets_is_active_false(): void
    {
        $category = CommunityCategory::factory()->inactive()->create();

        $this->assertFalse($category->is_active);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Domain/Ecosystem/CommunityCategoryTest.php`
Expected: FAIL — class doesn't exist yet.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_08_09_160004_create_community_categories_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_categories', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('label');
            $table->string('icon')->nullable();
            $table->integer('sort_order')->default(0);
            // Deactivate, never hard-delete — preserves FK integrity for
            // community_members/partners rows that already reference this
            // category (Unit 3 design, 2026-08-09).
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_categories');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php
// app/Domain/Ecosystem/Models/CommunityCategory.php

namespace App\Domain\Ecosystem\Models;

use App\Concerns\HasTranslations;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommunityCategory extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'key',
        'label',
        'icon',
        'sort_order',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function communityMembers(): HasMany
    {
        return $this->hasMany(CommunityMember::class, 'category_id');
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class, 'category_id');
    }
}
```

- [ ] **Step 5: Write the factory**

```php
<?php
// database/factories/CommunityCategoryFactory.php

namespace Database\Factories;

use App\Domain\Ecosystem\Models\CommunityCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunityCategory>
 */
class CommunityCategoryFactory extends Factory
{
    protected $model = CommunityCategory::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'label' => ['ar' => fake()->words(2, true), 'en' => fake()->words(2, true)],
            'icon' => null,
            'sort_order' => 0,
            'is_active' => true,
            'created_by' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/Domain/Ecosystem/CommunityCategoryTest.php`
Expected: PASS (all 3 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_09_160004_create_community_categories_table.php app/Domain/Ecosystem/Models/CommunityCategory.php database/factories/CommunityCategoryFactory.php tests/Unit/Domain/Ecosystem/CommunityCategoryTest.php
git commit -m "feat: add community_categories shared taxonomy table"
```

---

### Task 2: Admin category management

**Files:**
- Create: `app/Http/Requests/Admin/StoreCommunityCategoryRequest.php`
- Create: `app/Http/Requests/Admin/UpdateCommunityCategoryRequest.php`
- Create: `app/Http/Resources/CommunityCategoryResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/CommunityCategoryController.php`
- Modify: `routes/api/v1/admin.php` (add 4 routes)
- Test: `tests/Feature/Admin/CommunityCategoryControllerTest.php`

**Interfaces:**
- Consumes: `CommunityCategory` (Task 1).
- Produces: `GET/POST /api/v1/admin/community-categories`, `PATCH /api/v1/admin/community-categories/{communityCategory}`, `PATCH .../{communityCategory}/deactivate` — consumed by Task 5's guard test.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Admin/CommunityCategoryControllerTest.php

namespace Tests\Feature\Admin;

use App\Domain\Ecosystem\Models\CommunityCategory;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_an_admin_can_create_a_category(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/community-categories', [
            'key' => 'startups',
            'label' => ['ar' => 'شركات ناشئة', 'en' => 'Startups'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.key', 'startups');
        $response->assertJsonPath('data.created_by', $admin->id);
        $response->assertJsonPath('data.is_active', true);
    }

    public function test_deactivating_a_category_does_not_delete_it(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $category = CommunityCategory::factory()->create();

        $response = $this->patchJson("/api/v1/admin/community-categories/{$category->id}/deactivate");

        $response->assertNoContent();
        $this->assertDatabaseHas('community_categories', ['id' => $category->id, 'is_active' => false]);
    }

    public function test_an_admin_can_update_a_categorys_label(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $category = CommunityCategory::factory()->create(['key' => 'startups']);

        $response = $this->patchJson("/api/v1/admin/community-categories/{$category->id}", [
            'key' => 'startups',
            'label' => ['ar' => 'شركات ناشئة جديد', 'en' => 'Startups Updated'],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.label.en', 'Startups Updated');
    }

    public function test_index_lists_categories_ordered_by_sort_order(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $second = CommunityCategory::factory()->create(['sort_order' => 2]);
        $first = CommunityCategory::factory()->create(['sort_order' => 1]);

        $response = $this->getJson('/api/v1/admin/community-categories');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Admin/CommunityCategoryControllerTest.php`
Expected: FAIL — routes don't exist (404).

- [ ] **Step 3: Write the Form Requests**

```php
<?php
// app/Http/Requests/Admin/StoreCommunityCategoryRequest.php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommunityCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(TranslatableField::rules('label'), [
            'key' => ['required', 'string', 'max:60', Rule::unique('community_categories', 'key')],
            'icon' => ['nullable', 'string', 'max:60'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
```

```php
<?php
// app/Http/Requests/Admin/UpdateCommunityCategoryRequest.php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class UpdateCommunityCategoryRequest extends StoreCommunityCategoryRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'key' => ['required', 'string', 'max:60', Rule::unique('community_categories', 'key')->ignore($this->route('communityCategory'))],
        ]);
    }
}
```

- [ ] **Step 4: Write the Resource and controller**

```php
<?php
// app/Http/Resources/CommunityCategoryResource.php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
        ];
    }
}
```

```php
<?php
// app/Http/Controllers/Api/V1/Admin/CommunityCategoryController.php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\CommunityCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCommunityCategoryRequest;
use App\Http\Requests\Admin\UpdateCommunityCategoryRequest;
use App\Http\Resources\CommunityCategoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Doesn't extend AdminResourceController: categories are never
 * hard-deleted (Unit 3 design, 2026-08-09) — `deactivate` sets
 * `is_active = false` instead, to preserve FK integrity for
 * community_members/partners rows that already reference the category.
 */
class CommunityCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CommunityCategoryResource::collection(
            CommunityCategory::query()->orderBy('sort_order')->get()
        );
    }

    public function store(StoreCommunityCategoryRequest $request): CommunityCategoryResource
    {
        return new CommunityCategoryResource(CommunityCategory::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]));
    }

    public function update(UpdateCommunityCategoryRequest $request, CommunityCategory $communityCategory): CommunityCategoryResource
    {
        $communityCategory->update($request->validated());

        return new CommunityCategoryResource($communityCategory);
    }

    public function deactivate(CommunityCategory $communityCategory): Response
    {
        $communityCategory->update(['is_active' => false]);

        return response()->noContent();
    }
}
```

- [ ] **Step 5: Wire the routes**

In `routes/api/v1/admin.php`, add the import:

```php
use App\Http\Controllers\Api\V1\Admin\CommunityCategoryController;
```

and add, at top level:

```php
Route::get('community-categories', [CommunityCategoryController::class, 'index']);
Route::post('community-categories', [CommunityCategoryController::class, 'store']);
Route::patch('community-categories/{communityCategory}', [CommunityCategoryController::class, 'update']);
Route::patch('community-categories/{communityCategory}/deactivate', [CommunityCategoryController::class, 'deactivate']);
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Admin/CommunityCategoryControllerTest.php`
Expected: PASS (all 4 tests)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Requests/Admin/StoreCommunityCategoryRequest.php app/Http/Requests/Admin/UpdateCommunityCategoryRequest.php app/Http/Resources/CommunityCategoryResource.php app/Http/Controllers/Api/V1/Admin/CommunityCategoryController.php routes/api/v1/admin.php tests/Feature/Admin/CommunityCategoryControllerTest.php
git commit -m "feat: add admin community-category management"
```

---

### Task 3: `community_members.category` → `category_id`

**Files:**
- Create: `database/migrations/2026_08_09_160005_replace_category_enum_with_category_id_on_community_members_table.php`
- Modify: `app/Domain/Ecosystem/Models/CommunityMember.php`
- Modify: `app/Http/Requests/Admin/StoreCommunityMemberRequest.php` (`UpdateCommunityMemberRequest` inherits automatically)
- Modify: `app/Http/Resources/CommunityMemberResource.php`
- Modify: `app/Http/Controllers/Api/V1/Public/CommunityMemberController.php` (adapt `?category=` filter to the new schema)
- Modify: `tests/Feature/Ecosystem/CommunityMemberDefaultsTest.php` (pre-existing regression test — drop its now-invalid `category` field)
- Test: `tests/Feature/Ecosystem/CommunityMemberCategoryTest.php`

**Interfaces:**
- Consumes: `CommunityCategory` (Task 1).
- Produces: `CommunityMember::category(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Ecosystem/CommunityMemberCategoryTest.php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\CommunityCategory;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunityMemberCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_an_admin_can_create_a_community_member_with_a_category(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $category = CommunityCategory::factory()->create(['key' => 'startups']);

        $response = $this->postJson('/api/v1/admin/community-members', [
            'name' => ['ar' => 'لينا حداد', 'en' => 'Lina Haddad'],
            'category_id' => $category->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category.id', $category->id);
        $response->assertJsonPath('data.category.key', 'startups');
    }

    public function test_a_community_member_can_be_created_with_no_category_yet(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/community-members', [
            'name' => ['ar' => 'لينا حداد', 'en' => 'Lina Haddad'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', null);
    }

    public function test_the_public_listing_can_filter_by_category_key(): void
    {
        $startups = CommunityCategory::factory()->create(['key' => 'startups']);
        $corporations = CommunityCategory::factory()->create(['key' => 'corporations']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/admin/community-members', [
            'name' => ['ar' => 'أ', 'en' => 'A'],
            'category_id' => $startups->id,
            'published' => true,
        ])->assertCreated();
        $this->postJson('/api/v1/admin/community-members', [
            'name' => ['ar' => 'ب', 'en' => 'B'],
            'category_id' => $corporations->id,
            'published' => true,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/public/community-members?category=startups');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name.en', 'A');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ecosystem/CommunityMemberCategoryTest.php`
Expected: FAIL — `category` is still `required`+enum-validated, and the column is still the old enum.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_08_09_160005_replace_category_enum_with_category_id_on_community_members_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverses the community_members.category enum — see
 * docs/decisions/unified-community-categories.md. No transition period:
 * dropped entirely, replaced by a shared lookup table. Nullable because
 * there is no seed data yet to backfill existing rows against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_members', function (Blueprint $table) {
            $table->dropColumn('category');
            $table->foreignId('category_id')->nullable()->after('name')->constrained('community_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('community_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->enum('category', ['pioneers', 'growth_partners', 'investors', 'impact_partners'])->after('name');
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Domain/Ecosystem/Models/CommunityMember.php`, change the `$fillable` array's `'category'` entry to `'category_id'`, add this import:

```php
use App\Domain\Ecosystem\Models\CommunityCategory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

and add this method:

```php
    public function category(): BelongsTo
    {
        return $this->belongsTo(CommunityCategory::class);
    }
```

- [ ] **Step 5: Update the Store Form Request**

In `app/Http/Requests/Admin/StoreCommunityMemberRequest.php`, replace:

```php
                'category' => ['required', Rule::in(['pioneers', 'growth_partners', 'investors', 'impact_partners'])],
```

with:

```php
                'category_id' => ['nullable', Rule::exists('community_categories', 'id')->where('is_active', true)],
```

(`UpdateCommunityMemberRequest extends StoreCommunityMemberRequest {}` needs no change.)

- [ ] **Step 6: Update the Resource**

In `app/Http/Resources/CommunityMemberResource.php`, replace:

```php
            'category' => $this->category,
```

with:

```php
            'category' => $this->category ? [
                'id' => $this->category->id,
                'key' => $this->category->key,
                'label' => $this->category->label,
            ] : null,
```

- [ ] **Step 7: Update the public filter**

In `app/Http/Controllers/Api/V1/Public/CommunityMemberController.php`, replace the full `scopeQuery` method body:

```php
    protected function scopeQuery(Builder $query): Builder
    {
        $query->where('published', true)->orderBy('order');

        if ($categoryKey = request()->query('category')) {
            $query->whereHas('category', fn (Builder $q) => $q->where('key', $categoryKey));
        }

        return $query;
    }
```

(the filter now matches the category's `key` slug, not the old raw enum value)

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test tests/Feature/Ecosystem/CommunityMemberCategoryTest.php`
Expected: PASS (all 3 tests)

- [ ] **Step 9: Run the two pre-existing regression tests for this resource**

Run: `php artisan test tests/Feature/Ecosystem/CommunityMemberDefaultsTest.php`
Expected: **FAIL** — it posts `'category' => 'investors'`, which no longer exists as a column. Update that test's request body: remove the `'category' => 'investors'` line entirely (the field is now optional and unrelated to this regression test's concern, which is about `order`/`published` defaults). Re-run and confirm PASS.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_08_09_160005_replace_category_enum_with_category_id_on_community_members_table.php app/Domain/Ecosystem/Models/CommunityMember.php app/Http/Requests/Admin/StoreCommunityMemberRequest.php app/Http/Resources/CommunityMemberResource.php app/Http/Controllers/Api/V1/Public/CommunityMemberController.php tests/Feature/Ecosystem/CommunityMemberCategoryTest.php tests/Feature/Ecosystem/CommunityMemberDefaultsTest.php
git commit -m "feat: replace community_members.category enum with shared category_id FK"
```

---

### Task 4: `partners.category` → `category_id`

**Files:**
- Create: `database/migrations/2026_08_09_160006_replace_category_enum_with_category_id_on_partners_table.php`
- Modify: `app/Domain/Ecosystem/Models/Partner.php`
- Modify: `app/Http/Requests/Admin/StorePartnerRequest.php`
- Modify: `app/Http/Resources/PartnerResource.php`
- Modify: `tests/Feature/Ecosystem/PartnerDefaultsTest.php` (pre-existing regression test — drop its now-invalid `category` field)
- Test: `tests/Feature/Ecosystem/PartnerCategoryTest.php`

**Interfaces:**
- Consumes: `CommunityCategory` (Task 1).
- Produces: `Partner::category(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Ecosystem/PartnerCategoryTest.php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\CommunityCategory;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PartnerCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_an_admin_can_create_a_partner_with_a_category(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $category = CommunityCategory::factory()->create(['key' => 'corporations']);

        $response = $this->postJson('/api/v1/admin/partners', [
            'name' => ['ar' => 'بنك سوريا', 'en' => 'Bank of Syria'],
            'category_id' => $category->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category.id', $category->id);
        $response->assertJsonPath('data.category.key', 'corporations');
    }

    public function test_a_partner_can_be_created_with_no_category_yet(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/partners', [
            'name' => ['ar' => 'بنك سوريا', 'en' => 'Bank of Syria'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', null);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Ecosystem/PartnerCategoryTest.php`
Expected: FAIL — `category` is still `required`+enum-validated, and the column is still the old enum.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_08_09_160006_replace_category_enum_with_category_id_on_partners_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverses the partners.category enum — see
 * docs/decisions/unified-community-categories.md. The docs/ERD referred
 * to a `partner_type` 7-value enum that was never actually implemented;
 * the real column being replaced is `category` enum('local','global').
 * No transition period. Nullable for the same reason as
 * community_members.category_id (no seed data yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('category');
            $table->foreignId('category_id')->nullable()->after('name')->constrained('community_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->enum('category', ['local', 'global'])->after('name');
        });
    }
};
```

- [ ] **Step 4: Update the model**

In `app/Domain/Ecosystem/Models/Partner.php`, change the `$fillable` array's `'category'` entry to `'category_id'`, add these imports:

```php
use App\Domain\Ecosystem\Models\CommunityCategory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
```

and add this method:

```php
    public function category(): BelongsTo
    {
        return $this->belongsTo(CommunityCategory::class);
    }
```

- [ ] **Step 5: Update the Store Form Request**

In `app/Http/Requests/Admin/StorePartnerRequest.php`, replace:

```php
                'category' => ['required', Rule::in(['local', 'global'])],
```

with:

```php
                'category_id' => ['nullable', Rule::exists('community_categories', 'id')->where('is_active', true)],
```

- [ ] **Step 6: Update the Resource**

In `app/Http/Resources/PartnerResource.php`, replace:

```php
            'category' => $this->category,
```

with:

```php
            'category' => $this->category ? [
                'id' => $this->category->id,
                'key' => $this->category->key,
                'label' => $this->category->label,
            ] : null,
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Ecosystem/PartnerCategoryTest.php`
Expected: PASS (both tests)

- [ ] **Step 8: Run the pre-existing regression test for this resource**

Run: `php artisan test tests/Feature/Ecosystem/PartnerDefaultsTest.php`
Expected: **FAIL** — it posts `'category' => 'local'`, which no longer exists as a column. Update that test's request body: remove the `'category' => 'local'` line entirely. Re-run and confirm PASS.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_09_160006_replace_category_enum_with_category_id_on_partners_table.php app/Domain/Ecosystem/Models/Partner.php app/Http/Requests/Admin/StorePartnerRequest.php app/Http/Resources/PartnerResource.php tests/Feature/Ecosystem/PartnerCategoryTest.php tests/Feature/Ecosystem/PartnerDefaultsTest.php
git commit -m "feat: replace partners.category enum with shared category_id FK"
```

---

### Task 5: Guard test — shared taxonomy + inactive-category rejection

**Files:**
- Test: `tests/Guards/SharedCommunityTaxonomyTest.php`

**Interfaces:**
- Consumes: `POST /api/v1/admin/community-members`, `POST /api/v1/admin/partners` (Tasks 3–4).

- [ ] **Step 1: Write the guard test**

```php
<?php
// tests/Guards/SharedCommunityTaxonomyTest.php

namespace Tests\Guards;

use App\Domain\Ecosystem\Models\CommunityCategory;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unit 3 design (2026-08-09): community_members and partners share ONE
 * taxonomy table, and neither may be created against a deactivated
 * category.
 */
class SharedCommunityTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_community_members_and_partners_category_id_reference_the_same_table(): void
    {
        $communityMembersFk = collect(Schema::getForeignKeys('community_members'))->firstWhere('columns', ['category_id']);
        $partnersFk = collect(Schema::getForeignKeys('partners'))->firstWhere('columns', ['category_id']);

        $this->assertNotNull($communityMembersFk, 'community_members.category_id has no foreign key.');
        $this->assertNotNull($partnersFk, 'partners.category_id has no foreign key.');
        $this->assertSame('community_categories', $communityMembersFk['foreign_table']);
        $this->assertSame('community_categories', $partnersFk['foreign_table']);
    }

    public function test_a_community_member_cannot_be_created_against_an_inactive_category(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $inactive = CommunityCategory::factory()->inactive()->create();

        $response = $this->postJson('/api/v1/admin/community-members', [
            'name' => ['ar' => 'أ', 'en' => 'A'],
            'category_id' => $inactive->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('community_members', ['category_id' => $inactive->id]);
    }

    public function test_a_partner_cannot_be_created_against_an_inactive_category(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
        $inactive = CommunityCategory::factory()->inactive()->create();

        $response = $this->postJson('/api/v1/admin/partners', [
            'name' => ['ar' => 'أ', 'en' => 'A'],
            'category_id' => $inactive->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('partners', ['category_id' => $inactive->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `php artisan test tests/Guards/SharedCommunityTaxonomyTest.php`
Expected: PASS (all 3 tests) — Tasks 3–4 already implemented the `Rule::exists(...)->where('is_active', true)` validation and the shared FK target, so this guard should pass immediately. It exists to catch a *future* regression (e.g. someone adding a second lookup table), not today's code.

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: PASS across all three test suites (Unit, Feature, Guards).

- [ ] **Step 4: Commit**

```bash
git add tests/Guards/SharedCommunityTaxonomyTest.php
git commit -m "test: guard shared community-category taxonomy and inactive-category rejection"
```

---

### Task 6: Decision doc

**Files:**
- Create: `docs/decisions/unified-community-categories.md`
- Modify: `docs/decisions/README.md` (catalog entry)

**Interfaces:** none — documentation only.

- [ ] **Step 1: Write the decision doc**

```markdown
<!-- docs/decisions/unified-community-categories.md -->
# Unified Community Categories (reverses community_members.category and partners.category)

**Status:** resolved 2026-08-09
**Owner:** Maryam Asha
**Type:** reversal — replaces two previously locked enum columns, same
pattern as `docs/decisions/district-removed.md`.

## What each source said

- `community_members.category` was a raw MySQL enum: `pioneers`,
  `growth_partners`, `investors`, `impact_partners` (grandfathered in
  `tests/Guards/NoNewMysqlEnumColumnsTest.php`'s legacy allowlist,
  predating the backed-enum convention).
- `partners.category` was a separate raw MySQL enum: `local`, `global`.
  (The ERD/build-plan docs described a still-different, never-implemented
  `partners.partner_type` 7-value enum — that column never existed in the
  actual schema; `category` is the real one being replaced here.)
- Both were admin-managed, hardcoded, non-extensible without a migration.

## Decision

**Both enums are dropped entirely, with no transition period, and
replaced by a single shared `community_categories` lookup table**,
referenced by `community_members.category_id` and `partners.category_id`
(both FKs point at the same table — one taxonomy, not two).

## Why

Community-facing entity types (Support Organizations, Startups &
Scaleups, Corporations, Service Providers, ...) needed to be managed by
admins without a code deploy, and needed to be shared across
`community_members` and `partners` rather than duplicated per table.
Final category names are still under review — this decision ships the
schema only; seed data is deliberately deferred.

## What this changed in code

- New table `community_categories` (`key`, `label` json `{ar, en}`,
  `icon`, `sort_order`, `is_active`, `created_by`), model
  `App\Domain\Ecosystem\Models\CommunityCategory`.
- `community_members.category` (enum) dropped; `category_id` (nullable FK
  → `community_categories`) added.
- `partners.category` (enum) dropped; `category_id` (nullable FK →
  `community_categories`) added.
- `CommunityMember`/`Partner`: `category`/`category_id` fillable swap, new
  `category()` `belongsTo` relation on each.
- `Public\CommunityMemberController`'s `?category=` filter now matches
  the category's `key` slug instead of the old raw enum value.
- New `Admin\CommunityCategoryController` (index/store/update, plus a
  `deactivate` action instead of hard delete — rows are never removed,
  only deactivated, to preserve FK integrity for historical
  `community_members`/`partners` rows).

## Guard

`tests/Guards/SharedCommunityTaxonomyTest.php` — asserts
`community_members.category_id` and `partners.category_id` both have a
foreign key to `community_categories` (not divergent lookup tables), and
that creating either model against an `is_active = false` category is
rejected.
```

- [ ] **Step 2: Update the decisions catalog**

Read `docs/decisions/README.md` and add one line for
`unified-community-categories.md` to its existing catalog/list, following
the exact same format as the `district-removed.md` entry already there.

- [ ] **Step 3: Commit**

```bash
git add docs/decisions/unified-community-categories.md docs/decisions/README.md
git commit -m "docs: record the unified-community-categories enum reversal"
```
