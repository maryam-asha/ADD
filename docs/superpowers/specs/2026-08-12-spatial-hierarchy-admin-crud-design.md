# Design: Spatial Hierarchy Admin CRUD

**Status:** approved for planning
**Owner:** Maryam Asha
**Date:** 2026-08-12

## Context

Phase 1 (Sprint 0, `S0-BE-01`/`S0-BE-02`) already shipped the seven-level
spatial hierarchy — `Branch → Building → Floor → Zone → Space → Resource /
SeatDesk`, plus `Device → DeviceCapability` off `Branch`/`Space` — as models,
migrations, and factories under `App\Domain\Foundation`. What's missing is
the admin-facing API surface: there is no `Admin\BranchController` or
equivalent for any of these 9 models, and none of them appear in
`routes/api/v1/admin.php`. The Admin Dashboard's spatial-hierarchy screens
(`S0-AD-01`/`S0-AD-02`, per `Downloads/ADD-OS-Sprints-Tasks.xlsx`) have
nothing to connect to yet. This design is exactly that missing layer:
full admin CRUD (+ one status-transition action) for all 9 models, following
this repo's existing two-tier controller / Form Request / Resource
conventions. Nothing here touches the public API, the frontend, or
TTLock/Access Control (Sprint 4) — those are separate, later pieces of work.

Two codebase-reality notes worth recording so they aren't re-discovered
later:
- `Device.type` and `DeviceCapability.capability` are still native MySQL
  `enum` columns (never migrated to the string+PHP-enum-cast pattern that
  `Space.space_type`/`Resource.category` use) — validated here with
  `Rule::in([...])` rather than introducing a new PHP enum class for them,
  since `tests/Guards/NoNewMysqlEnumColumnsTest.php` only forbids *new*
  enum-column declarations, not leaving an existing one alone, and there's
  no reason to touch a column this design doesn't otherwise need to change.
- `SeatDesk.qr_point_id` has no FK constraint yet (`qr_points` doesn't exist
  until Phase 7) — validated here as a plain nullable integer, not
  `exists:qr_points,id`.
- `Space.is_lockable` is a plain admin-editable boolean, not derived from
  `SpaceType::isLockable()`. The enum method describes which types are
  *capable* of being locked in principle; the column records whether a
  physical lock is actually installed on this specific space today — the
  two can legitimately disagree (a `room` that's lockable-by-type but has no
  lock fitted yet), so the request validates it as its own
  `['required', 'boolean']` field rather than defaulting/deriving it.

## Routes

Flat `apiResource` per level, added to `routes/api/v1/admin.php` (already
behind `auth:sanctum` + `role:admin|operations` from the group wrapping that
file — no extra middleware needed):

```
Route::apiResource('branches', BranchController::class);
Route::apiResource('buildings', BuildingController::class);
Route::apiResource('floors', FloorController::class);
Route::apiResource('zones', ZoneController::class);
Route::apiResource('spaces', SpaceController::class);
Route::apiResource('resources', ResourceController::class);
Route::apiResource('seats-desks', SeatDeskController::class)
    ->parameters(['seats-desks' => 'seatDesk']);
Route::apiResource('devices', DeviceController::class);
Route::apiResource('device-capabilities', DeviceCapabilityController::class)
    ->parameters(['device-capabilities' => 'deviceCapability']);

Route::patch('spaces/{space}/status', [SpaceController::class, 'updateStatus']);
Route::patch('resources/{resource}/status', [ResourceController::class, 'updateStatus']);
```

`App\Domain\Foundation\Models\Resource` shares a name with the generic API
term but not in code — the controller is
`App\Http\Controllers\Api\V1\Admin\ResourceController`, always referencing
the fully-qualified model where ambiguity could arise.

No nested parent-in-URL routes (e.g. `branches/{branch}/buildings`) — every
resource takes its parent id in the request body instead, same as
`Resource::space_id` and `SeatDesk::space_id` already do today.

## Controllers

All 9 extend `AdminResourceController` (free `index`/`show`/`destroy`) and
override `hasOrderColumn()` to return `false` — none of these tables have an
`order` column, unlike `Founder`/`Partner`. `store()`/`update()` are custom
per resource, each typed against its own Form Request pair.

`index()` gains optional parent-id query filters, layered onto the
inherited generic listing rather than replacing it — this is the one
deviation from the existing unfiltered-index convention, needed because the
Admin Dashboard's tree-navigation screens (`S0-AD-01`) lazy-load one level
at a time rather than pulling every row on every screen:

| Model | `index()` filters | `store`/`update` fields |
|---|---|---|
| Branch | — (top level) | `name`, `city` (translatable JSON), `timezone`, `is_active` |
| Building | `?branch_id=` | `branch_id`, `name` (translatable), `floor_count` |
| Floor | `?building_id=` | `building_id`, `label` (plain string), `sort_order` |
| Zone | `?floor_id=` | `floor_id`, `label`, `sort_order` |
| Space | `?building_id=`, `?zone_id=` | `building_id`, `zone_id` (nullable), `space_type`, `allocation_model` (nullable), `is_lockable`, `capacity`, `hourly_rate`, `pricing_currency` |
| Resource | `?space_id=` | `space_id`, `name`, `category`, `quantity` |
| SeatDesk | `?space_id=` | `space_id`, `label`, `qr_point_id` (nullable int) |
| Device | `?branch_id=`, `?space_id=` | `branch_id`, `space_id` (nullable), `type`, `external_ref`, `metadata` (nullable array), `status` |
| DeviceCapability | `?device_id=` | `device_id`, `capability` |

`Floor`/`Zone` deliberately carry no status field anywhere in this design —
`tests/Guards/SpatialHierarchyGuardTest.php` already enforces that they
never gain one.

`Space.update()` and `Resource.update()` exclude `status`/`status_reason`/
`status_from`/`status_until` entirely — those four fields only move through
`updateStatus()` below, never the generic update.

`Device.status` (`online`/`offline`/`faulted`) stays in `Device`'s generic
`update()` — no hardware writer exists yet (that's Sprint 4/TTLock), so
there's nothing for a manual admin edit to race against, unlike `Space`'s
status which already has a locked-down transition path.

## Status transitions (Space, Resource)

A dedicated, logged action — the same shape as `Company::updateStatus` and
`User::updateStatus` — satisfying Sprint 0's acceptance criterion that space
state transitions be "logged and enforced":

```php
public function updateStatus(UpdateSpaceStatusRequest $request, Space $space): JsonResponse
{
    $before = $space->status;
    $space->update($request->validated()); // status, status_reason, status_from, status_until

    $this->logSensitiveAction('space_status_changed', $space, [
        'before' => $before,
        'after' => $space->status,
    ]);

    return response()->json(['message' => __('api.admin.space_status_updated')]);
}
```

`ResourceController::updateStatus` mirrors this exactly (`resource_status_changed`
/ `resource_status_updated`). Both controllers `use LogsSensitiveActions`.

## Delete semantics

Plain hard delete via the inherited `AdminResourceController::destroy()` —
no added guard against deleting a node that still has children. The
database's own `cascadeOnDelete` foreign keys already define what happens
(deleting a `Branch` cascades through every `Building`/`Floor`/`Zone`/
`Space`/`Resource`/`SeatDesk`/`Device` beneath it); the controller layer
trusts that already-committed schema design rather than re-implementing a
second, independent safety check in front of it.

## Response conventions

Following the existing project-wide rule exactly:
- `store()` → returns the created resource (client needs the new id).
- `update()` and `updateStatus()` → `{'message' => __('api.admin....')}, ` never the resource.
- `index()`/`show()` → unaffected.
- New Resource classes: `BranchResource`, `BuildingResource`, `FloorResource`,
  `ZoneResource`, `SpaceResource`, `ResourceResource`, `SeatDeskResource`,
  `DeviceResource`, `DeviceCapabilityResource` — each a thin `toArray()`
  listing every column plus the immediate parent id(s), so the frontend can
  assemble the tree from flat per-level responses.
- New `lang/{en,ar}/api.php` keys under `admin`: `branch_updated`,
  `building_updated`, `floor_updated`, `zone_updated`, `space_updated`,
  `resource_updated`, `seat_desk_updated`, `device_updated`,
  `device_capability_updated`, `space_status_updated`, `resource_status_updated`.

## Validation

- `Branch.name`/`city` and `Building.name` use `TranslatableField::rules()`,
  same as `Founder`. `Floor.label`/`Zone.label` stay plain
  `['required', 'string']` — confirmed non-translatable by
  `docs/decisions/space-type-and-resource-status.md`'s sibling reasoning.
- Every foreign key gets a plain `['required', 'integer', 'exists:<table>,id']`
  (or `nullable` where the column is nullable) — matching
  `StoreCompanyRequest`'s style. No cross-hierarchy consistency checks (e.g.
  verifying a `Space.zone_id`'s zone actually belongs to the same
  `building_id`) — nothing else in the codebase does this kind of
  cross-field FK validation today, so this design doesn't introduce it
  either.
- `Space.pricing_currency` validated via the existing `Currency` enum
  (`Rule::enum(Currency::class)`, nullable).
- `Device.type` / `DeviceCapability.capability` validated with `Rule::in([...])`
  listing each migration's literal allowed values (see Context above for why
  no new enum class).
- Every `Update*Request` extends its `Store*Request` (same rules), same
  pattern as `UpdateFounderRequest extends StoreFounderRequest`.

## Tests

One Feature test file per resource directly under `tests/Feature/Foundation/`
(`BranchControllerTest`, `BuildingControllerTest`, ... `DeviceCapabilityControllerTest`)
— matching this domain's existing convention (`SpatialHierarchyTest.php`
lives there too, not in a `tests/Feature/Admin/` or `.../Admin/` subfolder;
that path is reserved for cross-cutting resources with no clear domain home,
like `ExchangeRateControllerTest`/`ErrorLogControllerTest`). Each file
covers `index` (incl. its parent-filter query param), `store`, `show`,
`update`, `destroy`, and — for `Space`/`Resource` — `updateStatus`, all as an
authenticated admin, plus one 403 check per file for a `member`-role token.
Any test asserting an exact localized message string sends
`->withHeader('lang', 'en')` first — without it, `SetLocaleFromHeader`
defaults the locale to Arabic, not English, matching every existing test of
this shape (`FounderUpdateTest`, `PartnerUpdateTest`, `UserUpdateTest`, ...).
No new guard tests — `SpatialHierarchyGuardTest` and `SpatialHierarchyTest`
already cover the schema-shape invariants this design must not violate, and
this design doesn't change the schema at all.
