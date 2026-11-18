# Spatial Hierarchy Admin CRUD Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the ADD Core admin API full CRUD (plus a logged status-transition action on `Space`/`Resource`) for all 9 Foundation-domain spatial/device models: `Branch`, `Building`, `Floor`, `Zone`, `Space`, `Resource`, `SeatDesk`, `Device`, `DeviceCapability`.

**Architecture:** Each resource gets a Form Request pair (`Store*Request`, `Update*Request extends Store*Request`), a `JsonResource`, and a controller extending the existing `Admin\AdminResourceController` (free `index`/`show`/`destroy`). One shared, additive change to `AdminResourceController` adds an optional parent-id filter hook that concrete controllers override. `Space`/`Resource` add one extra `PATCH .../status` action using the existing `LogsSensitiveActions` trait.

**Tech Stack:** Laravel 12, PHPUnit (`php artisan test`), Sanctum for auth-in-tests, `spatie/laravel-permission` for roles, `spatie/laravel-activitylog` for the sensitive-action log.

## Global Constraints

- Design source: `docs/superpowers/specs/2026-08-12-spatial-hierarchy-admin-crud-design.md` — every task below implements one row of that spec; do not deviate from field lists, route names, or the "hard delete, no cross-hierarchy FK check" decisions without re-opening that spec.
- Every route in `routes/api/v1/admin.php` already sits behind `auth:sanctum` + `role:admin|operations` (applied once by the group wrapping that file in `routes/api.php`) — never add per-route role middleware for these 9 resources.
- `update()`/`updateStatus()` return `response()->json(['message' => __('api.admin.<key>')])`, never the resource. `store()` returns the created resource. `index()`/`show()` are unaffected. This is a locked project-wide convention (`CLAUDE.md`), not a per-task choice.
- Every new Store/Update Form Request pair follows `UpdateXRequest extends StoreXRequest` (reuse rules, don't duplicate) — the existing pattern for every admin resource in this codebase (e.g. `UpdateFounderRequest extends StoreFounderRequest`).
- Translatable fields (`Branch.name`/`city`, `Building.name`) use `App\Support\TranslatableField::rules()`. Everything else is a plain scalar rule.
- Any DB column with a schema-level default that is *not* required in the Form Request must be explicitly defaulted in `store()` via `array_merge(['col' => default], $request->validated())` — omitting this makes the immediate `store()` response show `null` for that column even though the DB row is correct (the documented `FounderController`/`order` bug — see `database/migrations/2026_08_09_155541_backfill_null_defaults_on_founders_partners_community_members_plans.php`).
- Tests live under `tests/Feature/Foundation/` (this domain's existing convention — see `tests/Feature/Foundation/SpatialHierarchyTest.php` — not `tests/Feature/Admin/`), namespace `Tests\Feature\Foundation`.
- Run the full suite (`php artisan test`) at the end of every task, not just the new file — these controllers share `AdminResourceController` and the lang files with existing resources (Founder, Partner, Plan, ...); a regression there must be caught immediately.

---

## File Structure Overview

**Modified (shared, touched across multiple tasks):**
- `app/Http/Controllers/Api/V1/Admin/AdminResourceController.php` — Task 2 adds the `applyIndexFilters()` hook.
- `routes/api/v1/admin.php` — every task appends its own route lines.
- `lang/en/api.php`, `lang/ar/api.php` — every task appends its own `admin.*` keys.

**Created, one group per task:**

| Task | Controller | Requests | Resource | Factory | Test |
|---|---|---|---|---|---|
| 1 | `Admin/BranchController.php` | `Store\|UpdateBranchRequest` | `BranchResource` | *(exists)* | `Foundation/BranchControllerTest.php` |
| 2 | `Admin/BuildingController.php` | `Store\|UpdateBuildingRequest` | `BuildingResource` | *(exists)* | `Foundation/BuildingControllerTest.php` |
| 3 | `Admin/FloorController.php` | `Store\|UpdateFloorRequest` | `FloorResource` | *(exists)* | `Foundation/FloorControllerTest.php` |
| 4 | `Admin/ZoneController.php` | `Store\|UpdateZoneRequest` | `ZoneResource` | *(exists)* | `Foundation/ZoneControllerTest.php` |
| 5 | `Admin/SpaceController.php` | `Store\|UpdateSpaceRequest`, `UpdateSpaceStatusRequest` | `SpaceResource` | *(exists)* | `Foundation/SpaceControllerTest.php` |
| 6 | `Admin/ResourceController.php` | `Store\|UpdateResourceRequest`, `UpdateResourceStatusRequest` | `ResourceResource` | *(exists)* | `Foundation/ResourceControllerTest.php` |
| 7 | `Admin/SeatDeskController.php` | `Store\|UpdateSeatDeskRequest` | `SeatDeskResource` | *(exists)* | `Foundation/SeatDeskControllerTest.php` |
| 8 | `Admin/DeviceController.php` | `Store\|UpdateDeviceRequest` | `DeviceResource` | **new** `DeviceFactory.php` | `Foundation/DeviceControllerTest.php` |
| 9 | `Admin/DeviceCapabilityController.php` | `Store\|UpdateDeviceCapabilityRequest` | `DeviceCapabilityResource` | **new** `DeviceCapabilityFactory.php` | `Foundation/DeviceCapabilityControllerTest.php` |

All controllers live in `app/Http/Controllers/Api/V1/Admin/`, all requests in `app/Http/Requests/Admin/`, all resources in `app/Http/Resources/`, all tests in `tests/Feature/Foundation/`.

---

### Task 1: Branch admin CRUD

**Files:**
- Create: `app/Http/Requests/Admin/StoreBranchRequest.php`
- Create: `app/Http/Requests/Admin/UpdateBranchRequest.php`
- Create: `app/Http/Resources/BranchResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/BranchController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/BranchControllerTest.php`

**Interfaces:**
- Consumes: `App\Http\Controllers\Api\V1\Admin\AdminResourceController` (existing, unmodified in this task), `App\Domain\Foundation\Models\Branch` (existing), `App\Support\TranslatableField::rules()` (existing).
- Produces: `BranchController` with inherited `index()`/`show()`/`destroy()` (no filter — Branch is the top of the hierarchy) plus `store()`/`update()`. `BranchResource` shape: `{id, name, city, timezone, is_active}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/BranchControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchControllerTest extends TestCase
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

    public function test_admin_can_create_a_branch_and_is_active_defaults_true(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/branches', [
            'name' => ['ar' => 'الفرع الرئيسي', 'en' => 'Main Branch'],
            'city' => ['ar' => 'حلب', 'en' => 'Aleppo'],
            'timezone' => 'Asia/Damascus',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('branches', ['timezone' => 'Asia/Damascus', 'is_active' => 1]);
    }

    public function test_admin_can_list_branches(): void
    {
        $this->actingAsAdmin();
        Branch::factory()->count(2)->create();

        $this->getJson('/api/v1/admin/branches')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_show_a_branch(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $this->getJson("/api/v1/admin/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $branch->id);
    }

    public function test_admin_can_update_a_branch_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/branches/{$branch->id}", [
            'name' => $branch->name,
            'city' => $branch->city,
            'timezone' => 'Asia/Riyadh',
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Branch updated.']);
        $this->assertSame('Asia/Riyadh', $branch->fresh()->timezone);
        $this->assertFalse($branch->fresh()->is_active);
    }

    public function test_admin_can_delete_a_branch(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $this->deleteJson("/api/v1/admin/branches/{$branch->id}")->assertNoContent();
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
    }

    public function test_a_member_cannot_access_branch_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/branches')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/BranchControllerTest.php`
Expected: FAIL — route `/api/v1/admin/branches` doesn't exist (404).

- [ ] **Step 3: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreBranchRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
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
        return array_merge(
            TranslatableField::rules('name'),
            TranslatableField::rules('city'),
            [
                'timezone' => ['required', 'string', 'max:255'],
                'is_active' => ['nullable', 'boolean'],
            ]
        );
    }
}
```

Create `app/Http/Requests/Admin/UpdateBranchRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateBranchRequest extends StoreBranchRequest {}
```

- [ ] **Step 4: Write the Resource**

Create `app/Http/Resources/BranchResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'city' => $this->city,
            'timezone' => $this->timezone,
            'is_active' => $this->is_active,
        ];
    }
}
```

- [ ] **Step 5: Write the Controller**

Create `app/Http/Controllers/Api/V1/Admin/BranchController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Branch;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use Illuminate\Http\JsonResponse;

class BranchController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Branch::class;
    }

    protected function resourceClass(): string
    {
        return BranchResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    public function store(StoreBranchRequest $request): BranchResource
    {
        return new BranchResource(Branch::create(array_merge(
            ['is_active' => true],
            $request->validated()
        )));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validated());

        return response()->json(['message' => __('api.admin.branch_updated')]);
    }
}
```

- [ ] **Step 6: Wire the route**

Modify `routes/api/v1/admin.php` — add the import and a new "Spatial Hierarchy" section. Add this import alongside the existing `use` block:

```php
use App\Http\Controllers\Api\V1\Admin\BranchController;
```

Add this route, e.g. directly after the `Route::get('me', ...)` line:

```php
// Spatial Hierarchy — Branch is the top level (docs/decisions/district-removed.md).
Route::apiResource('branches', BranchController::class);
```

- [ ] **Step 7: Add the lang keys**

In `lang/en/api.php`, inside the `'admin' => [ ... ]` array, add:

```php
'branch_updated' => 'Branch updated.',
```

In `lang/ar/api.php`, inside the same array, add:

```php
'branch_updated' => 'تم تحديث الفرع.',
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/BranchControllerTest.php`
Expected: PASS (6 tests).

- [ ] **Step 9: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS — no existing test touches `branches`, so this should be a clean addition.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/Admin/StoreBranchRequest.php app/Http/Requests/Admin/UpdateBranchRequest.php app/Http/Resources/BranchResource.php app/Http/Controllers/Api/V1/Admin/BranchController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/BranchControllerTest.php
git commit -m "feat: add admin CRUD for Branch"
```

---

### Task 2: Extend `AdminResourceController` with an index filter hook, then Building admin CRUD

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/AdminResourceController.php`
- Create: `app/Http/Requests/Admin/StoreBuildingRequest.php`
- Create: `app/Http/Requests/Admin/UpdateBuildingRequest.php`
- Create: `app/Http/Resources/BuildingResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/BuildingController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/BuildingControllerTest.php`

**Interfaces:**
- Consumes: `Branch` (Task 1), `AdminResourceController::index()` (existing, about to be extended in place — every other admin controller that already extends it, e.g. `FounderController`, keeps working unchanged since the new hook is a no-op by default).
- Produces: `AdminResourceController::applyIndexFilters(Builder $query, Request $request): void` — a protected, empty-by-default hook every later task (3–9) overrides. `BuildingController` with `?branch_id=` filtering on `index()`. `BuildingResource` shape: `{id, branch_id, name, floor_count}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/BuildingControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BuildingControllerTest extends TestCase
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

    public function test_admin_can_create_a_building(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/buildings', [
            'branch_id' => $branch->id,
            'name' => ['ar' => 'المبنى الأول', 'en' => 'Building One'],
            'floor_count' => 4,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('buildings', ['branch_id' => $branch->id, 'floor_count' => 4]);
    }

    public function test_index_can_be_filtered_by_branch_id(): void
    {
        $this->actingAsAdmin();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        Building::factory()->for($branchA)->create();
        Building::factory()->for($branchB)->create();

        $response = $this->getJson("/api/v1/admin/buildings?branch_id={$branchA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.branch_id', $branchA->id);
    }

    public function test_index_without_a_filter_returns_every_building(): void
    {
        $this->actingAsAdmin();
        Building::factory()->count(3)->create();

        $this->getJson('/api/v1/admin/buildings')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_admin_can_update_a_building_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/buildings/{$building->id}", [
            'branch_id' => $building->branch_id,
            'name' => $building->name,
            'floor_count' => 9,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Building updated.']);
        $this->assertSame(9, $building->fresh()->floor_count);
    }

    public function test_admin_can_delete_a_building(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $this->deleteJson("/api/v1/admin/buildings/{$building->id}")->assertNoContent();
        $this->assertDatabaseMissing('buildings', ['id' => $building->id]);
    }

    public function test_a_member_cannot_access_building_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/buildings')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/BuildingControllerTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Add the filter hook to `AdminResourceController`**

Modify `app/Http/Controllers/Api/V1/Admin/AdminResourceController.php` to the following full contents:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * The part of admin CRUD that's identical for every resource here (list,
 * show, delete) lives once, in one place. Create/update stay on the
 * concrete controllers because that's exactly where they differ — a typed
 * Form Request per resource, not a rules() array trying to be generic.
 */
abstract class AdminResourceController extends Controller
{
    abstract protected function modelClass(): string;

    abstract protected function resourceClass(): string;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ($this->modelClass())::query();

        if ($this->hasOrderColumn()) {
            $query->orderBy('order');
        }

        $this->applyIndexFilters($query, $request);

        return ($this->resourceClass())::collection($query->get());
    }

    public function show(int $id): JsonResource
    {
        $model = ($this->modelClass())::findOrFail($id);

        return new ($this->resourceClass())($model);
    }

    public function destroy(int $id): Response
    {
        ($this->modelClass())::findOrFail($id)->delete();

        return response()->noContent();
    }

    protected function hasOrderColumn(): bool
    {
        return true;
    }

    /**
     * No-op by default. Concrete controllers whose index() should support
     * parent-id query filters (e.g. `buildings?branch_id=`) override this
     * instead of re-implementing index() from scratch.
     */
    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        //
    }
}
```

- [ ] **Step 4: Write the Building Form Requests**

Create `app/Http/Requests/Admin/StoreBuildingRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;

class StoreBuildingRequest extends FormRequest
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
        return array_merge(
            TranslatableField::rules('name'),
            [
                'branch_id' => ['required', 'integer', 'exists:branches,id'],
                'floor_count' => ['required', 'integer', 'min:1'],
            ]
        );
    }
}
```

Create `app/Http/Requests/Admin/UpdateBuildingRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateBuildingRequest extends StoreBuildingRequest {}
```

- [ ] **Step 5: Write the Building Resource**

Create `app/Http/Resources/BuildingResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BuildingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'floor_count' => $this->floor_count,
        ];
    }
}
```

- [ ] **Step 6: Write the Building Controller**

Create `app/Http/Controllers/Api/V1/Admin/BuildingController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Building;
use App\Http\Requests\Admin\StoreBuildingRequest;
use App\Http\Requests\Admin\UpdateBuildingRequest;
use App\Http\Resources\BuildingResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BuildingController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Building::class;
    }

    protected function resourceClass(): string
    {
        return BuildingResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('branch_id'),
            fn (Builder $q) => $q->where('branch_id', $request->query('branch_id'))
        );
    }

    public function store(StoreBuildingRequest $request): BuildingResource
    {
        return new BuildingResource(Building::create($request->validated()));
    }

    public function update(UpdateBuildingRequest $request, Building $building): JsonResponse
    {
        $building->update($request->validated());

        return response()->json(['message' => __('api.admin.building_updated')]);
    }
}
```

- [ ] **Step 7: Wire the route**

Add to the `use` block in `routes/api/v1/admin.php`:

```php
use App\Http\Controllers\Api\V1\Admin\BuildingController;
```

Add directly after the `branches` route from Task 1:

```php
Route::apiResource('buildings', BuildingController::class);
```

- [ ] **Step 8: Add the lang keys**

`lang/en/api.php`, inside `'admin' => [ ... ]`:

```php
'building_updated' => 'Building updated.',
```

`lang/ar/api.php`, inside `'admin' => [ ... ]`:

```php
'building_updated' => 'تم تحديث المبنى.',
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/BuildingControllerTest.php`
Expected: PASS (6 tests).

- [ ] **Step 10: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS — in particular, every other `index()` consumer (`FounderControllerTest`-style coverage, `ExchangeRateControllerTest::test_index_returns_rates_ordered_by_effective_from_descending`, etc.) must still pass unchanged, since `applyIndexFilters()` is a no-op for them.

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/Api/V1/Admin/AdminResourceController.php app/Http/Requests/Admin/StoreBuildingRequest.php app/Http/Requests/Admin/UpdateBuildingRequest.php app/Http/Resources/BuildingResource.php app/Http/Controllers/Api/V1/Admin/BuildingController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/BuildingControllerTest.php
git commit -m "feat: add index() parent filtering hook and admin CRUD for Building"
```

---

### Task 3: Floor admin CRUD

**Files:**
- Create: `app/Http/Requests/Admin/StoreFloorRequest.php`
- Create: `app/Http/Requests/Admin/UpdateFloorRequest.php`
- Create: `app/Http/Resources/FloorResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/FloorController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/FloorControllerTest.php`

**Interfaces:**
- Consumes: `Building` (Task 2), `AdminResourceController::applyIndexFilters()` hook (Task 2).
- Produces: `FloorController` with `?building_id=` filtering. `FloorResource` shape: `{id, building_id, label, sort_order}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/FloorControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FloorControllerTest extends TestCase
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

    public function test_admin_can_create_a_floor_and_sort_order_defaults_to_zero(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->postJson('/api/v1/admin/floors', [
            'building_id' => $building->id,
            'label' => 'Ground',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $this->assertDatabaseHas('floors', ['building_id' => $building->id, 'label' => 'Ground', 'sort_order' => 0]);
    }

    public function test_index_can_be_filtered_by_building_id(): void
    {
        $this->actingAsAdmin();
        $buildingA = Building::factory()->create();
        $buildingB = Building::factory()->create();
        Floor::factory()->for($buildingA)->create();
        Floor::factory()->for($buildingB)->create();

        $response = $this->getJson("/api/v1/admin/floors?building_id={$buildingA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_floor_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $floor = Floor::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/floors/{$floor->id}", [
            'building_id' => $floor->building_id,
            'label' => 'Mezzanine',
            'sort_order' => 2,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Floor updated.']);
        $this->assertSame('Mezzanine', $floor->fresh()->label);
    }

    public function test_admin_can_delete_a_floor(): void
    {
        $this->actingAsAdmin();
        $floor = Floor::factory()->create();

        $this->deleteJson("/api/v1/admin/floors/{$floor->id}")->assertNoContent();
        $this->assertDatabaseMissing('floors', ['id' => $floor->id]);
    }

    public function test_a_member_cannot_access_floor_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/floors')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/FloorControllerTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreFloorRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreFloorRequest extends FormRequest
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
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'label' => ['required', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
```

Create `app/Http/Requests/Admin/UpdateFloorRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateFloorRequest extends StoreFloorRequest {}
```

- [ ] **Step 4: Write the Resource**

Create `app/Http/Resources/FloorResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FloorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'label' => $this->label,
            'sort_order' => $this->sort_order,
        ];
    }
}
```

- [ ] **Step 5: Write the Controller**

Create `app/Http/Controllers/Api/V1/Admin/FloorController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Floor;
use App\Http\Requests\Admin\StoreFloorRequest;
use App\Http\Requests\Admin\UpdateFloorRequest;
use App\Http\Resources\FloorResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FloorController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Floor::class;
    }

    protected function resourceClass(): string
    {
        return FloorResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('building_id'),
            fn (Builder $q) => $q->where('building_id', $request->query('building_id'))
        );
    }

    public function store(StoreFloorRequest $request): FloorResource
    {
        return new FloorResource(Floor::create(array_merge(
            ['sort_order' => 0],
            $request->validated()
        )));
    }

    public function update(UpdateFloorRequest $request, Floor $floor): JsonResponse
    {
        $floor->update($request->validated());

        return response()->json(['message' => __('api.admin.floor_updated')]);
    }
}
```

- [ ] **Step 6: Wire the route**

Add to the `use` block in `routes/api/v1/admin.php`:

```php
use App\Http\Controllers\Api\V1\Admin\FloorController;
```

Add directly after the `buildings` route:

```php
Route::apiResource('floors', FloorController::class);
```

- [ ] **Step 7: Add the lang keys**

`lang/en/api.php`, inside `'admin' => [ ... ]`:

```php
'floor_updated' => 'Floor updated.',
```

`lang/ar/api.php`, inside `'admin' => [ ... ]`:

```php
'floor_updated' => 'تم تحديث الطابق.',
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/FloorControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 9: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/Admin/StoreFloorRequest.php app/Http/Requests/Admin/UpdateFloorRequest.php app/Http/Resources/FloorResource.php app/Http/Controllers/Api/V1/Admin/FloorController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/FloorControllerTest.php
git commit -m "feat: add admin CRUD for Floor"
```

---

### Task 4: Zone admin CRUD

**Files:**
- Create: `app/Http/Requests/Admin/StoreZoneRequest.php`
- Create: `app/Http/Requests/Admin/UpdateZoneRequest.php`
- Create: `app/Http/Resources/ZoneResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/ZoneController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/ZoneControllerTest.php`

**Interfaces:**
- Consumes: `Floor` (Task 3), `AdminResourceController::applyIndexFilters()` hook (Task 2).
- Produces: `ZoneController` with `?floor_id=` filtering. `ZoneResource` shape: `{id, floor_id, label, sort_order}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/ZoneControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ZoneControllerTest extends TestCase
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

    public function test_admin_can_create_a_zone_and_sort_order_defaults_to_zero(): void
    {
        $this->actingAsAdmin();
        $floor = Floor::factory()->create();

        $response = $this->postJson('/api/v1/admin/zones', [
            'floor_id' => $floor->id,
            'label' => 'Zone A',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $this->assertDatabaseHas('zones', ['floor_id' => $floor->id, 'label' => 'Zone A']);
    }

    public function test_index_can_be_filtered_by_floor_id(): void
    {
        $this->actingAsAdmin();
        $floorA = Floor::factory()->create();
        $floorB = Floor::factory()->create();
        Zone::factory()->for($floorA)->create();
        Zone::factory()->for($floorB)->create();

        $response = $this->getJson("/api/v1/admin/zones?floor_id={$floorA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_zone_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $zone = Zone::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/zones/{$zone->id}", [
            'floor_id' => $zone->floor_id,
            'label' => 'Zone B',
            'sort_order' => 1,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Zone updated.']);
        $this->assertSame('Zone B', $zone->fresh()->label);
    }

    public function test_admin_can_delete_a_zone(): void
    {
        $this->actingAsAdmin();
        $zone = Zone::factory()->create();

        $this->deleteJson("/api/v1/admin/zones/{$zone->id}")->assertNoContent();
        $this->assertDatabaseMissing('zones', ['id' => $zone->id]);
    }

    public function test_a_member_cannot_access_zone_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/zones')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/ZoneControllerTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreZoneRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreZoneRequest extends FormRequest
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
            'floor_id' => ['required', 'integer', 'exists:floors,id'],
            'label' => ['required', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
```

Create `app/Http/Requests/Admin/UpdateZoneRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateZoneRequest extends StoreZoneRequest {}
```

- [ ] **Step 4: Write the Resource**

Create `app/Http/Resources/ZoneResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZoneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'floor_id' => $this->floor_id,
            'label' => $this->label,
            'sort_order' => $this->sort_order,
        ];
    }
}
```

- [ ] **Step 5: Write the Controller**

Create `app/Http/Controllers/Api/V1/Admin/ZoneController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Zone;
use App\Http\Requests\Admin\StoreZoneRequest;
use App\Http\Requests\Admin\UpdateZoneRequest;
use App\Http\Resources\ZoneResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Zone::class;
    }

    protected function resourceClass(): string
    {
        return ZoneResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('floor_id'),
            fn (Builder $q) => $q->where('floor_id', $request->query('floor_id'))
        );
    }

    public function store(StoreZoneRequest $request): ZoneResource
    {
        return new ZoneResource(Zone::create(array_merge(
            ['sort_order' => 0],
            $request->validated()
        )));
    }

    public function update(UpdateZoneRequest $request, Zone $zone): JsonResponse
    {
        $zone->update($request->validated());

        return response()->json(['message' => __('api.admin.zone_updated')]);
    }
}
```

- [ ] **Step 6: Wire the route**

Add to the `use` block in `routes/api/v1/admin.php`:

```php
use App\Http\Controllers\Api\V1\Admin\ZoneController;
```

Add directly after the `floors` route:

```php
Route::apiResource('zones', ZoneController::class);
```

- [ ] **Step 7: Add the lang keys**

`lang/en/api.php`, inside `'admin' => [ ... ]`:

```php
'zone_updated' => 'Zone updated.',
```

`lang/ar/api.php`, inside `'admin' => [ ... ]`:

```php
'zone_updated' => 'تم تحديث المنطقة.',
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/ZoneControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 9: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/Admin/StoreZoneRequest.php app/Http/Requests/Admin/UpdateZoneRequest.php app/Http/Resources/ZoneResource.php app/Http/Controllers/Api/V1/Admin/ZoneController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/ZoneControllerTest.php
git commit -m "feat: add admin CRUD for Zone"
```

---

### Task 5: Space admin CRUD + logged status transition

**Files:**
- Create: `app/Http/Requests/Admin/StoreSpaceRequest.php`
- Create: `app/Http/Requests/Admin/UpdateSpaceRequest.php`
- Create: `app/Http/Requests/Admin/UpdateSpaceStatusRequest.php`
- Create: `app/Http/Resources/SpaceResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/SpaceController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/SpaceControllerTest.php`

**Interfaces:**
- Consumes: `Building` (Task 2), `Zone` (Task 4), `App\Domain\Foundation\Enums\{SpaceType,AllocationModel,OperationalStatus}`, `App\Domain\Finance\Enums\Currency`, `App\Concerns\LogsSensitiveActions` (existing).
- Produces: `SpaceController` with `?building_id=`/`?zone_id=` filtering, `store()`/`update()` (structural fields only) and `updateStatus()` (status fields only, logs `space_status_changed`). `SpaceResource` shape: `{id, building_id, zone_id, space_type, allocation_model, is_lockable, capacity, hourly_rate, pricing_currency, status, status_reason, status_from, status_until}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/SpaceControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SpaceControllerTest extends TestCase
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

    public function test_admin_can_create_a_space_and_status_defaults_to_active(): void
    {
        $this->actingAsAdmin();
        $building = Building::factory()->create();

        $response = $this->postJson('/api/v1/admin/spaces', [
            'building_id' => $building->id,
            'space_type' => 'room',
            'is_lockable' => true,
            'capacity' => 4,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('spaces', ['building_id' => $building->id, 'status' => 'active']);
    }

    public function test_index_can_be_filtered_by_building_id_and_zone_id(): void
    {
        $admin = $this->actingAsAdmin();
        $zone = Zone::factory()->create();
        $matching = Space::factory()->create([
            'building_id' => $zone->floor->building_id,
            'zone_id' => $zone->id,
        ]);
        Space::factory()->create();

        $response = $this->getJson("/api/v1/admin/spaces?building_id={$matching->building_id}&zone_id={$zone->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_admin_can_update_structural_fields_without_touching_status(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create(['capacity' => 4]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/spaces/{$space->id}", [
            'building_id' => $space->building_id,
            'zone_id' => $space->zone_id,
            'space_type' => $space->space_type->value,
            'is_lockable' => $space->is_lockable,
            'capacity' => 10,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Space updated.']);
        $this->assertSame(10, $space->fresh()->capacity);
    }

    public function test_admin_can_transition_space_status_and_it_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $space = Space::factory()->create();

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/spaces/{$space->id}/status", [
            'status' => 'maintenance',
            'status_reason' => 'Carpet replacement',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Space status updated.']);
        $this->assertSame('maintenance', $space->fresh()->status->value);

        $activity = Activity::where('description', 'space_status_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('active', $activity->properties['before']);
    }

    public function test_admin_can_delete_a_space(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();

        $this->deleteJson("/api/v1/admin/spaces/{$space->id}")->assertNoContent();
        $this->assertDatabaseMissing('spaces', ['id' => $space->id]);
    }

    public function test_a_member_cannot_access_space_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/spaces')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/SpaceControllerTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreSpaceRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Finance\Enums\Currency;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpaceRequest extends FormRequest
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
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'space_type' => ['required', Rule::enum(SpaceType::class)],
            'allocation_model' => ['nullable', Rule::enum(AllocationModel::class)],
            'is_lockable' => ['required', 'boolean'],
            'capacity' => ['nullable', 'integer', 'min:0'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'pricing_currency' => ['nullable', Rule::enum(Currency::class)],
        ];
    }
}
```

Create `app/Http/Requests/Admin/UpdateSpaceRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateSpaceRequest extends StoreSpaceRequest {}
```

Create `app/Http/Requests/Admin/UpdateSpaceStatusRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\OperationalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpaceStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(OperationalStatus::class)],
            'status_reason' => ['nullable', 'string'],
            'status_from' => ['nullable', 'date'],
            'status_until' => ['nullable', 'date'],
        ];
    }
}
```

- [ ] **Step 4: Write the Resource**

Create `app/Http/Resources/SpaceResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpaceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'zone_id' => $this->zone_id,
            'space_type' => $this->space_type,
            'allocation_model' => $this->allocation_model,
            'is_lockable' => $this->is_lockable,
            'capacity' => $this->capacity,
            'hourly_rate' => $this->hourly_rate,
            'pricing_currency' => $this->pricing_currency,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'status_from' => $this->status_from,
            'status_until' => $this->status_until,
        ];
    }
}
```

- [ ] **Step 5: Write the Controller**

Create `app/Http/Controllers/Api/V1/Admin/SpaceController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Http\Requests\Admin\StoreSpaceRequest;
use App\Http\Requests\Admin\UpdateSpaceRequest;
use App\Http\Requests\Admin\UpdateSpaceStatusRequest;
use App\Http\Resources\SpaceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpaceController extends AdminResourceController
{
    use LogsSensitiveActions;

    protected function modelClass(): string
    {
        return Space::class;
    }

    protected function resourceClass(): string
    {
        return SpaceResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query
            ->when(
                $request->filled('building_id'),
                fn (Builder $q) => $q->where('building_id', $request->query('building_id'))
            )
            ->when(
                $request->filled('zone_id'),
                fn (Builder $q) => $q->where('zone_id', $request->query('zone_id'))
            );
    }

    public function store(StoreSpaceRequest $request): SpaceResource
    {
        return new SpaceResource(Space::create(array_merge(
            ['status' => OperationalStatus::Active],
            $request->validated()
        )));
    }

    public function update(UpdateSpaceRequest $request, Space $space): JsonResponse
    {
        $space->update($request->validated());

        return response()->json(['message' => __('api.admin.space_updated')]);
    }

    public function updateStatus(UpdateSpaceStatusRequest $request, Space $space): JsonResponse
    {
        $before = $space->status;

        $space->update($request->validated());

        $this->logSensitiveAction('space_status_changed', $space, [
            'before' => $before,
            'after' => $space->status,
        ]);

        return response()->json(['message' => __('api.admin.space_status_updated')]);
    }
}
```

- [ ] **Step 6: Wire the routes**

Add to the `use` block in `routes/api/v1/admin.php`:

```php
use App\Http\Controllers\Api\V1\Admin\SpaceController;
```

Add directly after the `zones` route:

```php
Route::apiResource('spaces', SpaceController::class);
Route::patch('spaces/{space}/status', [SpaceController::class, 'updateStatus']);
```

- [ ] **Step 7: Add the lang keys**

`lang/en/api.php`, inside `'admin' => [ ... ]`:

```php
'space_updated' => 'Space updated.',
'space_status_updated' => 'Space status updated.',
```

`lang/ar/api.php`, inside `'admin' => [ ... ]`:

```php
'space_updated' => 'تم تحديث المساحة.',
'space_status_updated' => 'تم تحديث حالة المساحة.',
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/SpaceControllerTest.php`
Expected: PASS (6 tests).

- [ ] **Step 9: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS — in particular `tests/Feature/Foundation/SpatialHierarchyTest.php` must still pass unchanged, since this task adds an API surface on top of `Space`/`Zone` without touching their model code.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/Admin/StoreSpaceRequest.php app/Http/Requests/Admin/UpdateSpaceRequest.php app/Http/Requests/Admin/UpdateSpaceStatusRequest.php app/Http/Resources/SpaceResource.php app/Http/Controllers/Api/V1/Admin/SpaceController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/SpaceControllerTest.php
git commit -m "feat: add admin CRUD and logged status transition for Space"
```

---

### Task 6: Resource (equipment) admin CRUD + logged status transition

**Files:**
- Create: `app/Http/Requests/Admin/StoreResourceRequest.php`
- Create: `app/Http/Requests/Admin/UpdateResourceRequest.php`
- Create: `app/Http/Requests/Admin/UpdateResourceStatusRequest.php`
- Create: `app/Http/Resources/ResourceResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/ResourceController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/ResourceControllerTest.php`

**Interfaces:**
- Consumes: `Space` (Task 5), `App\Domain\Foundation\Enums\{ResourceCategory,OperationalStatus}`, `App\Concerns\LogsSensitiveActions`.
- Produces: `Admin\ResourceController` (fully qualified as `App\Http\Controllers\Api\V1\Admin\ResourceController`, wrapping `App\Domain\Foundation\Models\Resource`) with `?space_id=` filtering, `store()`/`update()`, and `updateStatus()` (logs `resource_status_changed`). `ResourceResource` (`App\Http\Resources\ResourceResource`, wrapping the same model) shape: `{id, space_id, name, category, quantity, status, status_reason, status_from, status_until}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/ResourceControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ResourceControllerTest extends TestCase
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

    public function test_admin_can_create_a_resource_and_quantity_and_status_default(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();

        $response = $this->postJson('/api/v1/admin/resources', [
            'space_id' => $space->id,
            'name' => 'Projector',
            'category' => 'projector',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.quantity', 1);
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('resources', ['space_id' => $space->id, 'quantity' => 1, 'status' => 'active']);
    }

    public function test_index_can_be_filtered_by_space_id(): void
    {
        $this->actingAsAdmin();
        $spaceA = Space::factory()->create();
        $spaceB = Space::factory()->create();
        Resource::factory()->for($spaceA)->create();
        Resource::factory()->for($spaceB)->create();

        $response = $this->getJson("/api/v1/admin/resources?space_id={$spaceA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_resource_without_touching_status(): void
    {
        $this->actingAsAdmin();
        $resource = Resource::factory()->create(['quantity' => 1]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/resources/{$resource->id}", [
            'space_id' => $resource->space_id,
            'name' => $resource->name,
            'category' => $resource->category->value,
            'quantity' => 3,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Resource updated.']);
        $this->assertSame(3, $resource->fresh()->quantity);
    }

    public function test_admin_can_transition_resource_status_and_it_is_logged(): void
    {
        $admin = $this->actingAsAdmin();
        $resource = Resource::factory()->create();

        $response = $this->withHeader('lang', 'en')->patchJson("/api/v1/admin/resources/{$resource->id}/status", [
            'status' => 'maintenance',
            'status_reason' => 'Bulb replacement',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Resource status updated.']);
        $this->assertSame('maintenance', $resource->fresh()->status->value);

        $activity = Activity::where('description', 'resource_status_changed')->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_admin_can_delete_a_resource(): void
    {
        $this->actingAsAdmin();
        $resource = Resource::factory()->create();

        $this->deleteJson("/api/v1/admin/resources/{$resource->id}")->assertNoContent();
        $this->assertDatabaseMissing('resources', ['id' => $resource->id]);
    }

    public function test_a_member_cannot_access_resource_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/resources')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/ResourceControllerTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreResourceRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\ResourceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreResourceRequest extends FormRequest
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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(ResourceCategory::class)],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
```

Create `app/Http/Requests/Admin/UpdateResourceRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateResourceRequest extends StoreResourceRequest {}
```

Create `app/Http/Requests/Admin/UpdateResourceStatusRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\OperationalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResourceStatusRequest extends FormRequest
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
            'status' => ['required', Rule::enum(OperationalStatus::class)],
            'status_reason' => ['nullable', 'string'],
            'status_from' => ['nullable', 'date'],
            'status_until' => ['nullable', 'date'],
        ];
    }
}
```

- [ ] **Step 4: Write the Resource class**

Create `app/Http/Resources/ResourceResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'name' => $this->name,
            'category' => $this->category,
            'quantity' => $this->quantity,
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'status_from' => $this->status_from,
            'status_until' => $this->status_until,
        ];
    }
}
```

- [ ] **Step 5: Write the Controller**

Create `app/Http/Controllers/Api/V1/Admin/ResourceController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Resource;
use App\Http\Requests\Admin\StoreResourceRequest;
use App\Http\Requests\Admin\UpdateResourceRequest;
use App\Http\Requests\Admin\UpdateResourceStatusRequest;
use App\Http\Resources\ResourceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceController extends AdminResourceController
{
    use LogsSensitiveActions;

    protected function modelClass(): string
    {
        return Resource::class;
    }

    protected function resourceClass(): string
    {
        return ResourceResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('space_id'),
            fn (Builder $q) => $q->where('space_id', $request->query('space_id'))
        );
    }

    public function store(StoreResourceRequest $request): ResourceResource
    {
        return new ResourceResource(Resource::create(array_merge(
            ['quantity' => 1, 'status' => OperationalStatus::Active],
            $request->validated()
        )));
    }

    public function update(UpdateResourceRequest $request, Resource $resource): JsonResponse
    {
        $resource->update($request->validated());

        return response()->json(['message' => __('api.admin.resource_updated')]);
    }

    public function updateStatus(UpdateResourceStatusRequest $request, Resource $resource): JsonResponse
    {
        $before = $resource->status;

        $resource->update($request->validated());

        $this->logSensitiveAction('resource_status_changed', $resource, [
            'before' => $before,
            'after' => $resource->status,
        ]);

        return response()->json(['message' => __('api.admin.resource_status_updated')]);
    }
}
```

- [ ] **Step 6: Wire the routes**

Add to the `use` block in `routes/api/v1/admin.php`:

```php
use App\Http\Controllers\Api\V1\Admin\ResourceController;
```

Add directly after the `spaces` routes:

```php
Route::apiResource('resources', ResourceController::class);
Route::patch('resources/{resource}/status', [ResourceController::class, 'updateStatus']);
```

- [ ] **Step 7: Add the lang keys**

`lang/en/api.php`, inside `'admin' => [ ... ]`:

```php
'resource_updated' => 'Resource updated.',
'resource_status_updated' => 'Resource status updated.',
```

`lang/ar/api.php`, inside `'admin' => [ ... ]`:

```php
'resource_updated' => 'تم تحديث المورد.',
'resource_status_updated' => 'تم تحديث حالة المورد.',
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/ResourceControllerTest.php`
Expected: PASS (6 tests).

- [ ] **Step 9: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/Admin/StoreResourceRequest.php app/Http/Requests/Admin/UpdateResourceRequest.php app/Http/Requests/Admin/UpdateResourceStatusRequest.php app/Http/Resources/ResourceResource.php app/Http/Controllers/Api/V1/Admin/ResourceController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/ResourceControllerTest.php
git commit -m "feat: add admin CRUD and logged status transition for Resource"
```

---

### Task 7: SeatDesk admin CRUD

**Files:**
- Create: `app/Http/Requests/Admin/StoreSeatDeskRequest.php`
- Create: `app/Http/Requests/Admin/UpdateSeatDeskRequest.php`
- Create: `app/Http/Resources/SeatDeskResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/SeatDeskController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/SeatDeskControllerTest.php`

**Interfaces:**
- Consumes: `Space` (Task 5).
- Produces: `SeatDeskController` with `?space_id=` filtering. `SeatDeskResource` shape: `{id, space_id, label, qr_point_id}`. Route parameter name `seatDesk` (multi-word URL segment `seats-desks`, same reasoning as `community-members` in `CLAUDE.md`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/SeatDeskControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeatDeskControllerTest extends TestCase
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

    public function test_admin_can_create_a_seat_desk(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();

        $response = $this->postJson('/api/v1/admin/seats-desks', [
            'space_id' => $space->id,
            'label' => 'D-12',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('seats_desks', ['space_id' => $space->id, 'label' => 'D-12']);
    }

    public function test_index_can_be_filtered_by_space_id(): void
    {
        $this->actingAsAdmin();
        $spaceA = Space::factory()->create();
        $spaceB = Space::factory()->create();
        SeatDesk::factory()->for($spaceA)->create();
        SeatDesk::factory()->for($spaceB)->create();

        $response = $this->getJson("/api/v1/admin/seats-desks?space_id={$spaceA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_seat_desk_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $seatDesk = SeatDesk::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/seats-desks/{$seatDesk->id}", [
            'space_id' => $seatDesk->space_id,
            'label' => 'D-99',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Seat/desk updated.']);
        $this->assertSame('D-99', $seatDesk->fresh()->label);
    }

    public function test_admin_can_delete_a_seat_desk(): void
    {
        $this->actingAsAdmin();
        $seatDesk = SeatDesk::factory()->create();

        $this->deleteJson("/api/v1/admin/seats-desks/{$seatDesk->id}")->assertNoContent();
        $this->assertDatabaseMissing('seats_desks', ['id' => $seatDesk->id]);
    }

    public function test_a_member_cannot_access_seat_desk_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/seats-desks')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/SeatDeskControllerTest.php`
Expected: FAIL — route doesn't exist.

- [ ] **Step 3: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreSeatDeskRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeatDeskRequest extends FormRequest
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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'label' => ['required', 'string', 'max:50'],
            'qr_point_id' => ['nullable', 'integer'],
        ];
    }
}
```

Create `app/Http/Requests/Admin/UpdateSeatDeskRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateSeatDeskRequest extends StoreSeatDeskRequest {}
```

- [ ] **Step 4: Write the Resource**

Create `app/Http/Resources/SeatDeskResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeatDeskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'label' => $this->label,
            'qr_point_id' => $this->qr_point_id,
        ];
    }
}
```

- [ ] **Step 5: Write the Controller**

Create `app/Http/Controllers/Api/V1/Admin/SeatDeskController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\SeatDesk;
use App\Http\Requests\Admin\StoreSeatDeskRequest;
use App\Http\Requests\Admin\UpdateSeatDeskRequest;
use App\Http\Resources\SeatDeskResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeatDeskController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return SeatDesk::class;
    }

    protected function resourceClass(): string
    {
        return SeatDeskResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('space_id'),
            fn (Builder $q) => $q->where('space_id', $request->query('space_id'))
        );
    }

    public function store(StoreSeatDeskRequest $request): SeatDeskResource
    {
        return new SeatDeskResource(SeatDesk::create($request->validated()));
    }

    public function update(UpdateSeatDeskRequest $request, SeatDesk $seatDesk): JsonResponse
    {
        $seatDesk->update($request->validated());

        return response()->json(['message' => __('api.admin.seat_desk_updated')]);
    }
}
```

- [ ] **Step 6: Wire the route**

Add to the `use` block in `routes/api/v1/admin.php`:

```php
use App\Http\Controllers\Api\V1\Admin\SeatDeskController;
```

Add directly after the `resources` routes:

```php
// Multi-word resource name — same reason as community-members above.
Route::apiResource('seats-desks', SeatDeskController::class)
    ->parameters(['seats-desks' => 'seatDesk']);
```

- [ ] **Step 7: Add the lang keys**

`lang/en/api.php`, inside `'admin' => [ ... ]`:

```php
'seat_desk_updated' => 'Seat/desk updated.',
```

`lang/ar/api.php`, inside `'admin' => [ ... ]`:

```php
'seat_desk_updated' => 'تم تحديث المقعد/المكتب.',
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/SeatDeskControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 9: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/Admin/StoreSeatDeskRequest.php app/Http/Requests/Admin/UpdateSeatDeskRequest.php app/Http/Resources/SeatDeskResource.php app/Http/Controllers/Api/V1/Admin/SeatDeskController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/SeatDeskControllerTest.php
git commit -m "feat: add admin CRUD for SeatDesk"
```

---

### Task 8: Device admin CRUD (+ missing factory)

**Files:**
- Create: `database/factories/DeviceFactory.php`
- Create: `app/Http/Requests/Admin/StoreDeviceRequest.php`
- Create: `app/Http/Requests/Admin/UpdateDeviceRequest.php`
- Create: `app/Http/Resources/DeviceResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/DeviceController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/DeviceControllerTest.php`

**Interfaces:**
- Consumes: `Branch` (Task 1), `Space` (Task 5). `App\Domain\Foundation\Models\Device` already declares `use HasFactory` but has no factory class yet — this task adds it.
- Produces: `DeviceFactory` (`Device::factory()`, default `type = 'lock'`, `status = 'offline'`, no `space_id`). `DeviceController` with `?branch_id=`/`?space_id=` filtering. `DeviceResource` shape: `{id, branch_id, space_id, type, external_ref, metadata, status}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/DeviceControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceControllerTest extends TestCase
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

    public function test_admin_can_create_a_device_and_status_defaults_to_offline(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/devices', [
            'branch_id' => $branch->id,
            'type' => 'lock',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'offline');
        $this->assertDatabaseHas('devices', ['branch_id' => $branch->id, 'type' => 'lock', 'status' => 'offline']);
    }

    public function test_index_can_be_filtered_by_branch_id_and_space_id(): void
    {
        $this->actingAsAdmin();
        $space = Space::factory()->create();
        $matching = Device::factory()->for($space)->create(['branch_id' => $space->building->branch_id]);
        Device::factory()->create();

        $response = $this->getJson("/api/v1/admin/devices?branch_id={$matching->branch_id}&space_id={$space->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_admin_can_update_a_device_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/devices/{$device->id}", [
            'branch_id' => $device->branch_id,
            'type' => $device->type,
            'status' => 'online',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Device updated.']);
        $this->assertSame('online', $device->fresh()->status);
    }

    public function test_admin_can_delete_a_device(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create();

        $this->deleteJson("/api/v1/admin/devices/{$device->id}")->assertNoContent();
        $this->assertDatabaseMissing('devices', ['id' => $device->id]);
    }

    public function test_a_member_cannot_access_device_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/devices')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/DeviceControllerTest.php`
Expected: FAIL — no factory for `Device` exists yet (`Device::factory()` throws), and the route doesn't exist either.

- [ ] **Step 3: Write the missing factory**

Create `database/factories/DeviceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'space_id' => null,
            'type' => 'lock',
            'external_ref' => null,
            'metadata' => null,
            'status' => 'offline',
        ];
    }
}
```

- [ ] **Step 4: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreDeviceRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
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
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'space_id' => ['nullable', 'integer', 'exists:spaces,id'],
            'type' => ['required', Rule::in([
                'lock', 'gateway', 'camera', 'gate', 'printer', 'display', 'occupancy_sensor', 'attendance_terminal',
            ])],
            'external_ref' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'status' => ['nullable', Rule::in(['online', 'offline', 'faulted'])],
        ];
    }
}
```

Create `app/Http/Requests/Admin/UpdateDeviceRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateDeviceRequest extends StoreDeviceRequest {}
```

- [ ] **Step 5: Write the Resource**

Create `app/Http/Resources/DeviceResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'space_id' => $this->space_id,
            'type' => $this->type,
            'external_ref' => $this->external_ref,
            'metadata' => $this->metadata,
            'status' => $this->status,
        ];
    }
}
```

- [ ] **Step 6: Write the Controller**

Create `app/Http/Controllers/Api/V1/Admin/DeviceController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Device;
use App\Http\Requests\Admin\StoreDeviceRequest;
use App\Http\Requests\Admin\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Device::class;
    }

    protected function resourceClass(): string
    {
        return DeviceResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query
            ->when(
                $request->filled('branch_id'),
                fn (Builder $q) => $q->where('branch_id', $request->query('branch_id'))
            )
            ->when(
                $request->filled('space_id'),
                fn (Builder $q) => $q->where('space_id', $request->query('space_id'))
            );
    }

    public function store(StoreDeviceRequest $request): DeviceResource
    {
        return new DeviceResource(Device::create(array_merge(
            ['status' => 'offline'],
            $request->validated()
        )));
    }

    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $device->update($request->validated());

        return response()->json(['message' => __('api.admin.device_updated')]);
    }
}
```

- [ ] **Step 7: Wire the route**

Add to the `use` block in `routes/api/v1/admin.php`:

```php
use App\Http\Controllers\Api\V1\Admin\DeviceController;
```

Add directly after the `seats-desks` route:

```php
Route::apiResource('devices', DeviceController::class);
```

- [ ] **Step 8: Add the lang keys**

`lang/en/api.php`, inside `'admin' => [ ... ]`:

```php
'device_updated' => 'Device updated.',
```

`lang/ar/api.php`, inside `'admin' => [ ... ]`:

```php
'device_updated' => 'تم تحديث الجهاز.',
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/DeviceControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 10: Run the full suite to check for regressions**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add database/factories/DeviceFactory.php app/Http/Requests/Admin/StoreDeviceRequest.php app/Http/Requests/Admin/UpdateDeviceRequest.php app/Http/Resources/DeviceResource.php app/Http/Controllers/Api/V1/Admin/DeviceController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/DeviceControllerTest.php
git commit -m "feat: add Device factory and admin CRUD"
```

---

### Task 9: DeviceCapability admin CRUD (+ missing factory)

**Files:**
- Create: `database/factories/DeviceCapabilityFactory.php`
- Create: `app/Http/Requests/Admin/StoreDeviceCapabilityRequest.php`
- Create: `app/Http/Requests/Admin/UpdateDeviceCapabilityRequest.php`
- Create: `app/Http/Resources/DeviceCapabilityResource.php`
- Create: `app/Http/Controllers/Api/V1/Admin/DeviceCapabilityController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Foundation/DeviceCapabilityControllerTest.php`

**Interfaces:**
- Consumes: `Device` (Task 8, including its new factory).
- Produces: `DeviceCapabilityFactory` (`DeviceCapability::factory()`, default `capability = 'generate_passcode'`). `DeviceCapabilityController` with `?device_id=` filtering. `DeviceCapabilityResource` shape: `{id, device_id, capability}`. Route parameter name `deviceCapability` (multi-word URL segment `device-capabilities`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/DeviceCapabilityControllerTest.php`:

```php
<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\DeviceCapability;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceCapabilityControllerTest extends TestCase
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

    public function test_admin_can_create_a_device_capability(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create();

        $response = $this->postJson('/api/v1/admin/device-capabilities', [
            'device_id' => $device->id,
            'capability' => 'revoke_passcode',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('device_capabilities', ['device_id' => $device->id, 'capability' => 'revoke_passcode']);
    }

    public function test_index_can_be_filtered_by_device_id(): void
    {
        $this->actingAsAdmin();
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        DeviceCapability::factory()->for($deviceA)->create();
        DeviceCapability::factory()->for($deviceB)->create();

        $response = $this->getJson("/api/v1/admin/device-capabilities?device_id={$deviceA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_admin_can_update_a_device_capability_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $capability = DeviceCapability::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/device-capabilities/{$capability->id}", [
            'device_id' => $capability->device_id,
            'capability' => 'stream',
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Device capability updated.']);
        $this->assertSame('stream', $capability->fresh()->capability);
    }

    public function test_admin_can_delete_a_device_capability(): void
    {
        $this->actingAsAdmin();
        $capability = DeviceCapability::factory()->create();

        $this->deleteJson("/api/v1/admin/device-capabilities/{$capability->id}")->assertNoContent();
        $this->assertDatabaseMissing('device_capabilities', ['id' => $capability->id]);
    }

    public function test_a_member_cannot_access_device_capability_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/device-capabilities')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Foundation/DeviceCapabilityControllerTest.php`
Expected: FAIL — no factory for `DeviceCapability` exists yet, and the route doesn't exist either.

- [ ] **Step 3: Write the missing factory**

Create `database/factories/DeviceCapabilityFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\DeviceCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCapability>
 */
class DeviceCapabilityFactory extends Factory
{
    protected $model = DeviceCapability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'capability' => 'generate_passcode',
        ];
    }
}
```

- [ ] **Step 4: Write the Form Requests**

Create `app/Http/Requests/Admin/StoreDeviceCapabilityRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceCapabilityRequest extends FormRequest
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
            'device_id' => ['required', 'integer', 'exists:devices,id'],
            'capability' => ['required', Rule::in([
                'generate_passcode', 'revoke_passcode', 'list_status', 'stream',
            ])],
        ];
    }
}
```

Create `app/Http/Requests/Admin/UpdateDeviceCapabilityRequest.php`:

```php
<?php

namespace App\Http\Requests\Admin;

class UpdateDeviceCapabilityRequest extends StoreDeviceCapabilityRequest {}
```

- [ ] **Step 5: Write the Resource**

Create `app/Http/Resources/DeviceCapabilityResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceCapabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_id' => $this->device_id,
            'capability' => $this->capability,
        ];
    }
}
```

- [ ] **Step 6: Write the Controller**

Create `app/Http/Controllers/Api/V1/Admin/DeviceCapabilityController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\DeviceCapability;
use App\Http\Requests\Admin\StoreDeviceCapabilityRequest;
use App\Http\Requests\Admin\UpdateDeviceCapabilityRequest;
use App\Http\Resources\DeviceCapabilityResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceCapabilityController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return DeviceCapability::class;
    }

    protected function resourceClass(): string
    {
        return DeviceCapabilityResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('device_id'),
            fn (Builder $q) => $q->where('device_id', $request->query('device_id'))
        );
    }

    public function store(StoreDeviceCapabilityRequest $request): DeviceCapabilityResource
    {
        return new DeviceCapabilityResource(DeviceCapability::create($request->validated()));
    }

    public function update(UpdateDeviceCapabilityRequest $request, DeviceCapability $deviceCapability): JsonResponse
    {
        $deviceCapability->update($request->validated());

        return response()->json(['message' => __('api.admin.device_capability_updated')]);
    }
}
```

- [ ] **Step 7: Wire the route**

Add to the `use` block in `routes/api/v1/admin.php`:

```php
use App\Http\Controllers\Api\V1\Admin\DeviceCapabilityController;
```

Add directly after the `devices` route:

```php
// Multi-word resource name — same reason as community-members above.
Route::apiResource('device-capabilities', DeviceCapabilityController::class)
    ->parameters(['device-capabilities' => 'deviceCapability']);
```

- [ ] **Step 8: Add the lang keys**

`lang/en/api.php`, inside `'admin' => [ ... ]`:

```php
'device_capability_updated' => 'Device capability updated.',
```

`lang/ar/api.php`, inside `'admin' => [ ... ]`:

```php
'device_capability_updated' => 'تم تحديث خاصية الجهاز.',
```

- [ ] **Step 9: Run the test to verify it passes**

Run: `php artisan test tests/Feature/Foundation/DeviceCapabilityControllerTest.php`
Expected: PASS (5 tests).

- [ ] **Step 10: Run the full suite to confirm everything is green**

Run: `php artisan test`
Expected: PASS — this is the last task, so this run is the final confirmation that all 9 resources coexist cleanly.

- [ ] **Step 11: Commit**

```bash
git add database/factories/DeviceCapabilityFactory.php app/Http/Requests/Admin/StoreDeviceCapabilityRequest.php app/Http/Requests/Admin/UpdateDeviceCapabilityRequest.php app/Http/Resources/DeviceCapabilityResource.php app/Http/Controllers/Api/V1/Admin/DeviceCapabilityController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Foundation/DeviceCapabilityControllerTest.php
git commit -m "feat: add DeviceCapability factory and admin CRUD"
```
