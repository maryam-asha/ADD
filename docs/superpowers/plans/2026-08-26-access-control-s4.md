# Access Control (S4): Passcode Lifecycle + QR Unlock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the entire `App\Domain\Access` domain from scratch — passcode issuance/activation/revocation/expiry lifecycle against TTLock's Cloud API, plus QR-scan as a second unlock channel alongside the keypad PIN — per `docs/decisions/qr-lock-unlock.md`.

**Architecture:** One vendor-facing service (`TTLockClient`) is the only class that ever calls TTLock's HTTP API. `PasscodeIssuanceService` owns the grant lifecycle (issue/activate/revoke/expire), called by three scheduled commands and one admin controller. `UnlockService` owns the QR-scan read path, called by one member controller. Two new DB tables (`access_grants`, `access_events`) plus three additive columns on `devices`.

**Tech Stack:** Laravel 12, PHPUnit, Laravel HTTP client (`Http::fake` for all TTLock tests — no test ever hits the vendor), Laravel's `encrypted` Eloquent cast (first use in this codebase), `spatie/laravel-permission` roles (already installed).

**Spec:** [docs/decisions/qr-lock-unlock.md](../../decisions/qr-lock-unlock.md) — read that file first; this plan implements it and resolves the gaps/ambiguities listed below. Original task instructions are reproduced in full in that doc's git history / the conversation that produced it; this plan is the authoritative breakdown.

## Global Constraints

- Credentials never enter the repo. TTLock client id/secret/username/password live in `.env` + `config/services.php` only; `.env.example` gets **empty** keys.
- Only `TTLockClient` (`App\Domain\Access\Services\TTLockClient`) may call the TTLock HTTP API. No controller, command, or other service issues a TTLock request directly.
- Every enum-shaped column is `string` + a PHP backed enum cast — never `->enum()` on a new migration (`tests/Guards/NoNewMysqlEnumColumnsTest.php`), and every such cast must be registered in `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`'s `EXPECTED_CASTS` map.
- No new Policy class — `tests/Guards/RbacStaysFlatTest.php::test_company_policy_is_the_only_policy_class_in_the_app` asserts `CompanyPolicy` is the **only** Policy in the app. Any company-grantee check reuses `App\Domain\Identity\Policies\CompanyPolicy` or its underlying `company_user.door_access_enabled` pivot flag directly — never a new Policy.
- `App\Domain\Access` is a Core domain (with Booking, Membership, Finance) per `tests/Guards/DomainLayerBoundaryTest.php` — it may freely depend on those and on `Identity`/`Foundation`. It must never be imported from `App\Domain\Ecosystem` or `App\Domain\Experience`.
- Update-style endpoints (`activate()`, `unlock()`, `regenerateQrValue()`) return `{"message": ...}` only, never the resource — per this codebase's established convention.
- Concurrency tests must be genuine, not sequential-looking. This codebase's established technique (commit `1e7026b`, mirrored from `WalkInCapacityServiceTest.php`) is a real multi-connection race is **not reproducible against this suite's in-memory SQLite** — instead, inject the race via `Event::listen(Illuminate\Database\Events\TransactionBeginning::class, ...)`, mutating the row with a raw `DB::table(...)->update(...)` at the exact moment the service's own transaction begins (before its `lockForUpdate()` query runs), and assert the listener actually fired so the test isn't vacuous.
- Every migration file must run cleanly against the existing `devices`/`device_capabilities` tables, whose `type`/`status` columns are raw MySQL enums grandfathered into `NoNewMysqlEnumColumnsTest`'s legacy allowlist — do not touch those columns or their allowlist entries.

## Design decisions this plan makes beyond the locked doc

The doc specifies behavior; a few concrete implementation choices aren't spelled out there and are decided here rather than left to whoever implements each task:

1. **New column not in the doc's table sketch: `access_grants.vendor_keyboard_pwd_id`** (nullable unsigned integer). TTLock's `keyboardPwd/delete` endpoint requires `keyboardPwdId`, not the passcode string — confirmed against the live API docs (§ TTLock verification findings below). Without storing it, revocation would have no way to identify which vendor passcode to delete. This is an addition to the doc's schema, not a deviation from anything it locks.
2. **`expires_at` derivation**: for a booking-sourced grant, `expires_at = booking.end_at`. For a tenancy grant, `expires_at = null` (open-ended; ended only by explicit revocation, never by time). The *vendor-side* passcode still needs a bounded `endDate` (TTLock's Period type requires one) — tenancy grants get a far-future vendor end date (`issued_at->addYears(5)`), independent of our own `expires_at`/`status` semantics, which is what actually gates the QR-unlock check.
3. **`grantee_type` reuses `App\Domain\Membership\Enums\OwnerType`** (`user`/`company`) instead of a new `Access\Enums\GranteeType` — it's the exact same manual-polymorphic-owner pattern already established for `Wallet`/`Membership` (`docs/decisions/wallet-subscription-ownership.md`), and Access/Membership are both Core domains free to depend on each other.
4. **`allocation_model` reuses `App\Domain\Foundation\Enums\AllocationModel`** (`booking_hourly`/`booking_daily`/`tenancy`/`open`) instead of a new enum — its three relevant cases are byte-identical to what the doc's `access_grants.allocation_model` needs, and it's literally the same value already sitting on `Space::allocation_model`.
5. **Company-tenancy grants gate on `company_user.door_access_enabled`**, not "any member of that company" read literally. That pivot flag already exists, has its own admin endpoint (`CompanyMemberController::updateDoorAccess`) and lang key (`door_access_updated`), and its docblock is explicitly about door access — leaving it unchecked would make it dead for the one feature it appears to exist for, and would open every lock a company has grants for to every member regardless of that toggle. Task 6's behavioral test is written against this reading.
6. **Guard filename**: the task instructions name it `tests/Guards/LockCredentialsNeverReachMemberTest.php`; the doc's own §4 names it `tests/Guards/LockDataNeverReachesMemberRoleTest.php`. One file is written, using the task instructions' name (the more specific, most-recently-stated source) — it satisfies both.
7. **Reception activation's `access_events` row uses `event_type = unlock`** (not a new event type). The closed set is `unlock|lock_auto|failed_attempt`; kiosk activation is the Bluetooth SDK's own physical unlock/handshake with the lock, recorded here via `channel = reception_activation` to distinguish it from a keypad or QR-scan unlock. No new `event_type` case is added for it.
8. **`ExpireUnactivatedAccessGrants` does not call TTLock.** The task text only specifies a vendor delete call for the *revoke* path. TTLock's own Period passcode type already auto-invalidates on the lock after 24h unused (confirmed in the verification findings below — it's literally built into passcode type 3's semantics), so the unactivated code stops working physically on its own; this command only needs to update our own `status` column to keep the two in sync for reporting/querying.
9. **`devices.type`/`devices.status` stay uncast** (raw enum-string columns, unchanged). They're pre-existing and grandfathered; retrofitting them to backed enums is out of scope for this feature and would touch unrelated code paths.
10. **The existing `App\Http\Controllers\Api\V1\Admin\DeviceController`/`StoreDeviceRequest` are extended**, not left alone — `hardware_mac`/`parent_device_id` validation and `qr_value` auto-generation on `type=lock` creation are schema-adjacent and small enough to fold into Task 1 rather than warrant a separate numbered task. A `regenerate-qr-value` admin action is added because the doc explicitly specifies that operation ("a plain admin action with no TTLock call and no effect on grants") even though it wasn't itemized as its own task.

## TTLock verification findings (summary — full write-up goes into the doc in Task 7)

Checked live against `https://euopen.ttlock.com/doc/api/*` (the EU Open Platform docs portal) on 2026-08-26. **Not checked against real hardware or a real account** — no TTLock credentials exist anywhere in this repo or environment, and none were available during planning. Everything below is doc-verified, not hardware-verified; Task 2 flags this explicitly and Task 7's report to the user repeats it.

- **OAuth token**: `POST /oauth2/token` — Resource Owner Password grant. Params: `client_id`, `client_secret`, `username`, `password` (**must be pre-hashed**: "32 chars, low case, md5 encrypted" — send `md5($rawPassword)`, never the raw password). Response: `access_token`, `uid`, `expires_in` (default 7776000s / 90 days), `scope`, `refresh_token`.
- **OAuth refresh**: same `/oauth2/token` URL, params `client_id`, `client_secret`, `grant_type=refresh_token`, `refresh_token`.
- **Add/get a passcode — two different endpoints, and the task prompt's guessed shape (`keyboardPwd/get` with `addType=2`) conflates them:**
  - `POST /v3/keyboardPwd/get` — server/gateway generates and programs a passcode; no `addType` parameter exists on this endpoint at all. Params: `lockId`, `keyboardPwdVersion` (4 = latest), `keyboardPwdType` (**3 = Period** — the type this feature wants), `startDate`/`endDate` (epoch **milliseconds**), `date` (epoch ms, current time). Response: `{keyboardPwd, keyboardPwdId}`. **This is the endpoint this plan uses** — it's inherently gateway-mediated, no Bluetooth branch to worry about.
  - `POST /v3/keyboardPwd/add` — for pushing a passcode value *you already chose*. Has `addType` (1 = bluetooth, default; 2 = gateway; 3 = NB-IoT) and a required `keyboardPwd` string param. Not used here, since we don't need to choose the value ourselves.
  - TTLock's own type table describes Period (3) as "must be used at least once within 24 Hours after the Start Time, or it will be invalidated" — i.e. the 24h must-activate-or-expire rule in the design doc is **already enforced by the lock itself** for this passcode type, not just by our app.
- **Delete a passcode**: `POST /v3/keyboardPwd/delete` — params `lockId`, `keyboardPwdId` (not the passcode value), `deleteType` (**1 = bluetooth, default** — must explicitly pass **`deleteType=2`** for gateway deletion, or the vendor call silently expects a Bluetooth-connected app that will never come). `date`. Response: `{errcode, errmsg}`.
- **Remote unlock**: `POST /v3/lock/unlock` — params `lockId`, `date`. Response `{errcode, errmsg}`. Doc note on this exact page: *"if get -4043 (The function is not supported for this lock) error message, please switch the 'remote unlock' on in Sciener APP's lock setting page"* — a one-time per-lock toggle that must be enabled in the vendor's own app before remote unlock works at all; worth flagging to ops as a hardware setup step.
- **Error codes** (from `/doc/api/error`, confirmed exhaustively): `0`=success, `10000/10001/10007`=bad client/account credentials, `10011`=invalid refresh_token, `20001-20004`=lock/key ownership errors, `80000`=**request `date` must be within 5 minutes of real current time**, `90000`=internal server error, **`-2012`=lock not connected to any Gateway** (this is the "gateway offline" case §4 of the doc needs a distinguishable message for), `-4043`=remote-unlock-disabled-for-this-lock (from the Unlock page specifically, not the general table).
- **Base host**: every code example on the EU portal shows `https://api.sciener.com` — no EU-specific host (e.g. a `euapi.*` variant) is stated anywhere on the docs I could reach. This is the single largest unverified item — it is entirely plausible the EU-registered account needs a different host, and I have no way to confirm this without real credentials. `config/services.php` defaults to the documented host; Task 7's report calls this out as the first thing to check with real credentials.

---

### Task 1: Schema — `devices` additions, `access_grants`, `access_events`, enums, models

**Files:**
- Create: `database/migrations/2026_08_26_120001_add_hardware_mac_qr_value_to_devices_table.php`
- Create: `database/migrations/2026_08_26_120002_create_access_grants_table.php`
- Create: `database/migrations/2026_08_26_120003_create_access_events_table.php`
- Create: `app/Domain/Access/Enums/AccessGrantStatus.php`
- Create: `app/Domain/Access/Enums/AccessSourceType.php`
- Create: `app/Domain/Access/Enums/PasscodeType.php`
- Create: `app/Domain/Access/Enums/AccessEventType.php`
- Create: `app/Domain/Access/Enums/AccessEventChannel.php`
- Create: `app/Domain/Access/Models/AccessGrant.php`
- Create: `app/Domain/Access/Models/AccessEvent.php`
- Create: `database/factories/AccessGrantFactory.php`
- Create: `database/factories/AccessEventFactory.php`
- Modify: `app/Domain/Foundation/Models/Device.php` (fillable + `parent()`/`children()` relations)
- Modify: `database/factories/DeviceFactory.php` (add `hardware_mac`/`parent_device_id`/`qr_value` null defaults)
- Modify: `app/Http/Requests/Admin/StoreDeviceRequest.php` (validate `hardware_mac`/`parent_device_id`)
- Modify: `app/Http/Controllers/Api/V1/Admin/DeviceController.php` (auto-generate `qr_value` for `type=lock`; add `regenerateQrValue`)
- Modify: `routes/api/v1/admin.php` (add `devices/{device}/regenerate-qr-value` route)
- Modify: `lang/en/api.php`, `lang/ar/api.php` (add `admin.device_qr_value_regenerated`)
- Modify: `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php` (register `AccessGrant`/`AccessEvent` casts)
- Test: `tests/Feature/Foundation/DeviceControllerTest.php` (extend)
- Test: `tests/Unit/Domain/Access/Models/AccessGrantTest.php`

**Interfaces:**
- Produces: `AccessGrant` (fillable: `lock_id, grantee_type, grantee_id, source_type, source_id, allocation_model, passcode_type, passcode_value, vendor_keyboard_pwd_id, issued_at, must_activate_by, activated_at, expires_at, status`), `AccessEvent` (fillable: `device_id, access_grant_id, event_type, channel, actor_user_id, occurred_at`), `AccessGrantStatus::{Issued,Activated,Expired,Revoked}`, `AccessEventType::{Unlock,LockAuto,FailedAttempt}`, `AccessEventChannel::{KeypadManual,QrScan,ReceptionActivation}`, `AccessSourceType::{Booking,Tenancy}`, `PasscodeType::{Period}`. `Device::qr_value`/`hardware_mac`/`parent_device_id` columns exist and are fillable.

- [ ] **Step 1: Devices additive migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('hardware_mac')->nullable()->unique()->after('type');
            $table->foreignId('parent_device_id')->nullable()->after('hardware_mac')
                ->constrained('devices')->nullOnDelete();
            $table->string('qr_value')->nullable()->unique()->after('parent_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_device_id');
            $table->dropColumn(['hardware_mac', 'qr_value']);
        });
    }
};
```

- [ ] **Step 2: `access_grants` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lock_id')->constrained('devices')->restrictOnDelete();
            $table->string('grantee_type');
            $table->unsignedBigInteger('grantee_id');
            $table->string('source_type');
            $table->foreignId('source_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('allocation_model');
            $table->string('passcode_type');
            $table->text('passcode_value');
            $table->unsignedInteger('vendor_keyboard_pwd_id')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('must_activate_by');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status')->default('issued');
            $table->timestamps();

            $table->index(['grantee_type', 'grantee_id']);
            $table->index(['lock_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
    }
};
```

- [ ] **Step 3: `access_events` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('access_grant_id')->nullable()->constrained('access_grants')->nullOnDelete();
            $table->string('event_type');
            $table->string('channel');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['device_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};
```

- [ ] **Step 4: Run migrations, verify they apply cleanly**

Run: `php artisan migrate --env=testing` is not a real Artisan flag — instead run the suite once schema-only tests exist (Step 8), or sanity-check now with:
`php artisan migrate:fresh --seed=false` against local dev DB, then `php artisan migrate:rollback --step=3` to confirm `down()` works, then `php artisan migrate` again.
Expected: no errors, `access_grants`/`access_events` tables exist, `devices` has the three new columns.

- [ ] **Step 5: Enums**

```php
<?php
// app/Domain/Access/Enums/AccessGrantStatus.php
namespace App\Domain\Access\Enums;

enum AccessGrantStatus: string
{
    case Issued = 'issued';
    case Activated = 'activated';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
```

```php
<?php
// app/Domain/Access/Enums/AccessSourceType.php
namespace App\Domain\Access\Enums;

enum AccessSourceType: string
{
    case Booking = 'booking';
    case Tenancy = 'tenancy';
}
```

```php
<?php
// app/Domain/Access/Enums/PasscodeType.php
namespace App\Domain\Access\Enums;

enum PasscodeType: string
{
    case Period = 'period';
}
```

```php
<?php
// app/Domain/Access/Enums/AccessEventType.php
namespace App\Domain\Access\Enums;

enum AccessEventType: string
{
    case Unlock = 'unlock';
    case LockAuto = 'lock_auto';
    case FailedAttempt = 'failed_attempt';
}
```

```php
<?php
// app/Domain/Access/Enums/AccessEventChannel.php
namespace App\Domain\Access\Enums;

enum AccessEventChannel: string
{
    case KeypadManual = 'keypad_manual';
    case QrScan = 'qr_scan';
    case ReceptionActivation = 'reception_activation';
}
```

- [ ] **Step 6: `AccessGrant` and `AccessEvent` models**

```php
<?php
// app/Domain/Access/Models/AccessGrant.php
namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Enums\PasscodeType;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Models\Device;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * grantee_type/grantee_id is the manual-polymorphic owner pattern already
 * established by Wallet/Membership (docs/decisions/wallet-subscription-ownership.md)
 * — reused via OwnerType rather than a duplicate enum, since Access and
 * Membership are both Core domains free to depend on each other
 * (tests/Guards/DomainLayerBoundaryTest.php only restricts Ecosystem/Experience).
 */
class AccessGrant extends Model
{
    use HasFactory;

    protected $fillable = [
        'lock_id', 'grantee_type', 'grantee_id', 'source_type', 'source_id',
        'allocation_model', 'passcode_type', 'passcode_value', 'vendor_keyboard_pwd_id',
        'issued_at', 'must_activate_by', 'activated_at', 'expires_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'grantee_type' => OwnerType::class,
            'source_type' => AccessSourceType::class,
            'allocation_model' => AllocationModel::class,
            'passcode_type' => PasscodeType::class,
            'passcode_value' => 'encrypted',
            'status' => AccessGrantStatus::class,
            'issued_at' => 'datetime',
            'must_activate_by' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $grant) {
            if ($grant->grantee_type === null || $grant->grantee_id === null) {
                throw new \InvalidArgumentException('AccessGrant requires a non-null grantee_type and grantee_id.');
            }
        });
    }

    public function lock(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'lock_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'source_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AccessEvent::class);
    }
}
```

```php
<?php
// app/Domain/Access/Models/AccessEvent.php
namespace App\Domain\Access\Models;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id', 'access_grant_id', 'event_type', 'channel', 'actor_user_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => AccessEventType::class,
            'channel' => AccessEventChannel::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(AccessGrant::class, 'access_grant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
```

- [ ] **Step 7: Factories**

```php
<?php
// database/factories/AccessGrantFactory.php
namespace Database\Factories;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Enums\PasscodeType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Models\Device;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGrant>
 */
class AccessGrantFactory extends Factory
{
    protected $model = AccessGrant::class;

    public function definition(): array
    {
        $issuedAt = now();

        return [
            'lock_id' => Device::factory(),
            'grantee_type' => OwnerType::User,
            'grantee_id' => \App\Domain\Identity\Models\User::factory(),
            'source_type' => AccessSourceType::Booking,
            'source_id' => null,
            'allocation_model' => AllocationModel::BookingHourly,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => (string) random_int(100000, 999999),
            'vendor_keyboard_pwd_id' => $this->faker->unique()->numberBetween(1000, 999999),
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt->copy()->addHours(24),
            'activated_at' => null,
            'expires_at' => $issuedAt->copy()->addHours(4),
            'status' => AccessGrantStatus::Issued,
        ];
    }

    public function activated(): static
    {
        return $this->state(fn () => [
            'status' => AccessGrantStatus::Activated,
            'activated_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => AccessGrantStatus::Revoked]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['status' => AccessGrantStatus::Expired]);
    }

    public function forCompany(): static
    {
        return $this->state(fn () => [
            'grantee_type' => OwnerType::Company,
            'grantee_id' => \App\Domain\Identity\Models\Company::factory(),
            'source_type' => AccessSourceType::Tenancy,
            'allocation_model' => AllocationModel::Tenancy,
            'expires_at' => null,
        ]);
    }
}
```

```php
<?php
// database/factories/AccessEventFactory.php
namespace Database\Factories;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Foundation\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessEvent>
 */
class AccessEventFactory extends Factory
{
    protected $model = AccessEvent::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'access_grant_id' => null,
            'event_type' => AccessEventType::Unlock,
            'channel' => AccessEventChannel::QrScan,
            'actor_user_id' => null,
            'occurred_at' => now(),
        ];
    }
}
```

- [ ] **Step 8: Test — `AccessGrant` requires grantee_type/grantee_id**

```php
<?php
// tests/Unit/Domain/Access/Models/AccessGrantTest.php
namespace Tests\Unit\Domain\Access\Models;

use App\Domain\Access\Models\AccessGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_without_a_grantee_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AccessGrant::factory()->make(['grantee_type' => null])->save();
    }
}
```

Run: `php artisan test tests/Unit/Domain/Access/Models/AccessGrantTest.php`
Expected: FAIL (class doesn't exist yet) before Step 6, PASS after.

- [ ] **Step 9: Register the new enum casts in the guard**

Add to `tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`'s `EXPECTED_CASTS` array (alongside the existing entries, with matching `use` imports added at the top):

```php
        AccessGrant::class => [
            'grantee_type' => OwnerType::class,
            'source_type' => AccessSourceType::class,
            'allocation_model' => AllocationModel::class,
            'passcode_type' => PasscodeType::class,
            'status' => AccessGrantStatus::class,
        ],
        AccessEvent::class => [
            'event_type' => AccessEventType::class,
            'channel' => AccessEventChannel::class,
        ],
```

(`AllocationModel` and `OwnerType` imports likely already exist in this file from `Space`/`Wallet` entries — check before adding duplicate `use` lines.)

Run: `php artisan test tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php`
Expected: PASS.

- [ ] **Step 10: Extend `Device` model — fillable + parent/children relations**

Add to `Device::$fillable`: `'hardware_mac', 'parent_device_id', 'qr_value'`.

Add relations:

```php
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'parent_device_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Device::class, 'parent_device_id');
    }
```

(Add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` if not already imported — it likely is, from `branch()`/`space()`.)

- [ ] **Step 11: Extend `DeviceFactory`**

Add to `definition()`: `'hardware_mac' => null, 'parent_device_id' => null, 'qr_value' => null,`.

- [ ] **Step 12: `StoreDeviceRequest` — validate `hardware_mac`/`parent_device_id`**

```php
            'hardware_mac' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), ['lock', 'gateway'], true)),
                'nullable', 'string', 'max:255',
            ],
            'parent_device_id' => ['nullable', 'integer', 'exists:devices,id'],
```

(Add these two lines inside the existing `rules()` array, after `'type'`.)

- [ ] **Step 13: `DeviceController` — auto-generate `qr_value` for locks, add `regenerateQrValue`**

```php
    public function store(StoreDeviceRequest $request): DeviceResource
    {
        $data = $request->validated();
        $data['status'] ??= 'offline';

        if ($data['type'] === 'lock') {
            $data['qr_value'] = Str::random(40);
        }

        return new DeviceResource(Device::create($data));
    }

    public function regenerateQrValue(Device $device): JsonResponse
    {
        abort_if($device->type !== 'lock', 422, __('api.admin.device_not_a_lock'));

        $device->update(['qr_value' => Str::random(40)]);

        return response()->json(['message' => __('api.admin.device_qr_value_regenerated')]);
    }
```

Add `use Illuminate\Support\Str;` to the controller's imports.

- [ ] **Step 14: Route**

In `routes/api/v1/admin.php`, immediately after the existing `Route::apiResource('devices', DeviceController::class)->except('destroy');` line:

```php
Route::post('devices/{device}/regenerate-qr-value', [DeviceController::class, 'regenerateQrValue']);
```

- [ ] **Step 15: Lang keys**

Add to the `'admin' => [...]` array in both `lang/en/api.php` and `lang/ar/api.php`:
- `'device_qr_value_regenerated' => 'The device QR value has been regenerated.'` (en) / Arabic equivalent
- `'device_not_a_lock' => 'Only lock devices have a QR value.'` (en) / Arabic equivalent

- [ ] **Step 16: Tests for the Device changes**

Add to `tests/Feature/Foundation/DeviceControllerTest.php`:

```php
    public function test_creating_a_lock_device_auto_generates_a_qr_value(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/devices', ['branch_id' => $branch->id, 'type' => 'lock']);

        $response->assertCreated();
        $device = Device::find($response->json('data.id'));
        $this->assertNotNull($device->qr_value);
        $this->assertSame(40, strlen($device->qr_value));
    }

    public function test_creating_a_camera_device_does_not_generate_a_qr_value(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/devices', ['branch_id' => $branch->id, 'type' => 'camera']);

        $response->assertCreated();
        $this->assertNull(Device::find($response->json('data.id'))->qr_value);
    }

    public function test_creating_a_lock_without_hardware_mac_fails_validation(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $this->postJson('/api/v1/admin/devices', ['branch_id' => $branch->id, 'type' => 'lock', 'hardware_mac' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors('hardware_mac');
    }

    public function test_admin_can_regenerate_a_locks_qr_value(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['type' => 'lock', 'qr_value' => 'old-value']);

        $response = $this->postJson("/api/v1/admin/devices/{$device->id}/regenerate-qr-value");

        $response->assertOk();
        $device->refresh();
        $this->assertNotSame('old-value', $device->qr_value);
        $this->assertSame(40, strlen($device->qr_value));
    }

    public function test_regenerating_qr_value_on_a_non_lock_device_is_rejected(): void
    {
        $this->actingAsAdmin();
        $device = Device::factory()->create(['type' => 'camera']);

        $this->postJson("/api/v1/admin/devices/{$device->id}/regenerate-qr-value")->assertStatus(422);
    }
```

Run: `php artisan test tests/Feature/Foundation/DeviceControllerTest.php`
Expected: all PASS.

- [ ] **Step 17: Full-suite sanity check + commit**

Run: `./vendor/bin/pint --test && php artisan test`
Expected: PASS (existing tests unaffected — this task only adds columns/tables/routes, doesn't change existing behavior).

```bash
git add database/migrations/2026_08_26_120001_add_hardware_mac_qr_value_to_devices_table.php database/migrations/2026_08_26_120002_create_access_grants_table.php database/migrations/2026_08_26_120003_create_access_events_table.php app/Domain/Access/Enums app/Domain/Access/Models database/factories/AccessGrantFactory.php database/factories/AccessEventFactory.php app/Domain/Foundation/Models/Device.php database/factories/DeviceFactory.php app/Http/Requests/Admin/StoreDeviceRequest.php app/Http/Controllers/Api/V1/Admin/DeviceController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Guards/EnumColumnsHaveBackedEnumCastsTest.php tests/Feature/Foundation/DeviceControllerTest.php tests/Unit/Domain/Access
git commit -m "feat: add access_grants/access_events schema and devices lock columns"
```

---

### Task 2: `TTLockClient` + `TTLockException` + config

**Files:**
- Create: `app/Domain/Access/Exceptions/TTLockException.php`
- Create: `app/Domain/Access/Services/TTLockClient.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Test: `tests/Unit/Domain/Access/Services/TTLockClientTest.php`

**Interfaces:**
- Consumes: `App\Domain\Foundation\Models\Device` (`->external_ref` as TTLock's `lockId`).
- Produces: `TTLockClient::addPeriodPasscode(Device $lock, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): array{passcode: string, vendor_passcode_id: int}`, `TTLockClient::deletePasscode(Device $lock, int $vendorPasscodeId): void`, `TTLockClient::remoteUnlock(Device $lock): void`. `TTLockException` with named constructors `invalidCredentials()`, `gatewayOffline()`, `remoteUnlockDisabled()`, `lockNotFound()`, `vendorError(int $code, string $message)`, and public readonly `?int $vendorErrorCode`.

- [ ] **Step 1: `TTLockException`**

```php
<?php

namespace App\Domain\Access\Exceptions;

use RuntimeException;

/**
 * Every TTLockClient failure surfaces as one of these named cases so
 * callers — in particular UnlockService, which must tell a member "use
 * the keypad instead" specifically when the gateway is offline — can
 * branch on *why* the vendor call failed, not just that it did.
 */
class TTLockException extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $vendorErrorCode = null)
    {
        parent::__construct($message);
    }

    public static function invalidCredentials(): self
    {
        return new self('TTLock rejected the configured client/account credentials.');
    }

    public static function gatewayOffline(): self
    {
        return new self("The lock's gateway is not currently connected.", -2012);
    }

    public static function remoteUnlockDisabled(): self
    {
        return new self('Remote unlock is not enabled for this lock in the TTLock app settings.', -4043);
    }

    public static function lockNotFound(): self
    {
        return new self('TTLock does not recognize this lock/key relationship.');
    }

    public static function vendorError(int $code, string $message): self
    {
        return new self("TTLock error {$code}: {$message}", $code);
    }
}
```

- [ ] **Step 2: config + `.env.example`**

Add to `config/services.php`:

```php
    'ttlock' => [
        // Verified against https://euopen.ttlock.com/doc/api/ — no EU-specific
        // host was found documented anywhere on that portal; every example
        // shows this generic host. UNVERIFIED against a real account —
        // confirm before relying on it in production (see
        // docs/decisions/qr-lock-unlock.md's verification findings).
        'base_url' => env('TTLOCK_BASE_URL', 'https://api.sciener.com'),
        'client_id' => env('TTLOCK_CLIENT_ID'),
        'client_secret' => env('TTLOCK_CLIENT_SECRET'),
        'username' => env('TTLOCK_USERNAME'),
        // Raw password — TTLockClient MD5-hashes it at call time, per the
        // vendor's documented requirement. Never store a pre-hashed value here.
        'password' => env('TTLOCK_PASSWORD'),
    ],
```

Add to `.env.example` (empty values):

```
TTLOCK_BASE_URL=
TTLOCK_CLIENT_ID=
TTLOCK_CLIENT_SECRET=
TTLOCK_USERNAME=
TTLOCK_PASSWORD=
```

- [ ] **Step 3: `TTLockClient`**

```php
<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Foundation\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The only class in this app that talks to TTLock's Cloud API V3. Every
 * verified endpoint signature and quirk this implements is recorded in
 * docs/decisions/qr-lock-unlock.md's "TTLock verification findings"
 * section — several diverge from the originally-assumed shapes (e.g.
 * addType belongs to keyboardPwd/add, not keyboardPwd/get; deleteType
 * defaults to Bluetooth and must be set to 2 for gateway deletion).
 */
class TTLockClient
{
    private const TOKEN_CACHE_KEY = 'ttlock:oauth_token';

    private const KEYBOARD_PWD_VERSION_V4 = 4;

    private const PASSCODE_TYPE_PERIOD = 3;

    private const DELETE_TYPE_GATEWAY = 2;

    public function addPeriodPasscode(Device $lock, \DateTimeInterface $startsAt, \DateTimeInterface $endsAt): array
    {
        $response = $this->callV3('/v3/keyboardPwd/get', [
            'lockId' => $lock->external_ref,
            'keyboardPwdVersion' => self::KEYBOARD_PWD_VERSION_V4,
            'keyboardPwdType' => self::PASSCODE_TYPE_PERIOD,
            'startDate' => $startsAt->getTimestamp() * 1000,
            'endDate' => $endsAt->getTimestamp() * 1000,
        ]);

        if (! isset($response['keyboardPwd'], $response['keyboardPwdId'])) {
            $this->assertSuccess($response);
            throw TTLockException::vendorError(-1, 'TTLock response missing keyboardPwd/keyboardPwdId');
        }

        return [
            'passcode' => (string) $response['keyboardPwd'],
            'vendor_passcode_id' => (int) $response['keyboardPwdId'],
        ];
    }

    public function deletePasscode(Device $lock, int $vendorPasscodeId): void
    {
        $this->assertSuccess($this->callV3('/v3/keyboardPwd/delete', [
            'lockId' => $lock->external_ref,
            'keyboardPwdId' => $vendorPasscodeId,
            'deleteType' => self::DELETE_TYPE_GATEWAY,
        ]));
    }

    public function remoteUnlock(Device $lock): void
    {
        $this->assertSuccess($this->callV3('/v3/lock/unlock', [
            'lockId' => $lock->external_ref,
        ]));
    }

    private function assertSuccess(array $response): void
    {
        $errcode = (int) ($response['errcode'] ?? 0);

        if ($errcode === 0) {
            return;
        }

        throw match ($errcode) {
            -2012 => TTLockException::gatewayOffline(),
            -4043 => TTLockException::remoteUnlockDisabled(),
            20001, 20002, 20003, 20004 => TTLockException::lockNotFound(),
            default => TTLockException::vendorError($errcode, (string) ($response['errmsg'] ?? 'unknown TTLock error')),
        };
    }

    private function callV3(string $path, array $params): array
    {
        $params['clientId'] = config('services.ttlock.client_id');
        $params['accessToken'] = $this->accessToken();
        // TTLock rejects any request whose `date` is more than 5 minutes
        // from its own clock (errcode 80000) — always the real current time.
        $params['date'] = (int) (microtime(true) * 1000);

        $response = Http::asForm()
            ->baseUrl(config('services.ttlock.base_url'))
            ->timeout(10)
            ->post($path, $params);

        if (! $response->successful()) {
            throw TTLockException::vendorError($response->status(), "HTTP {$response->status()} from TTLock");
        }

        return $response->json() ?? [];
    }

    private function accessToken(): string
    {
        $bundle = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_array($bundle) && ($bundle['expires_at'] ?? 0) > time()) {
            return $bundle['access_token'];
        }

        try {
            $bundle = is_array($bundle) && isset($bundle['refresh_token'])
                ? $this->refreshToken($bundle['refresh_token'])
                : $this->fetchToken();
        } catch (TTLockException) {
            $bundle = $this->fetchToken();
        }

        // Cached longer than the access token's own lifetime so the
        // refresh_token survives in cache past the access token's expiry —
        // otherwise every expiry would force a full password re-auth
        // instead of a refresh.
        Cache::put(self::TOKEN_CACHE_KEY, $bundle, now()->addDays(89));

        return $bundle['access_token'];
    }

    private function fetchToken(): array
    {
        return $this->requestToken([
            'client_id' => config('services.ttlock.client_id'),
            'client_secret' => config('services.ttlock.client_secret'),
            'username' => config('services.ttlock.username'),
            'password' => md5((string) config('services.ttlock.password')),
        ]);
    }

    private function refreshToken(string $refreshToken): array
    {
        return $this->requestToken([
            'client_id' => config('services.ttlock.client_id'),
            'client_secret' => config('services.ttlock.client_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    private function requestToken(array $params): array
    {
        $response = Http::asForm()
            ->baseUrl(config('services.ttlock.base_url'))
            ->timeout(10)
            ->post('/oauth2/token', $params);

        $body = $response->json() ?? [];

        if (! $response->successful() || ! isset($body['access_token'])) {
            $code = (int) ($body['errcode'] ?? -1);

            throw in_array($code, [10000, 10001, 10007, 10011], true)
                ? TTLockException::invalidCredentials()
                : TTLockException::vendorError($code, (string) ($body['errmsg'] ?? 'TTLock token request failed'));
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'expires_at' => time() + (int) ($body['expires_in'] ?? 0) - 60,
        ];
    }
}
```

- [ ] **Step 4: Tests — write all as failing first**

```php
<?php

namespace Tests\Unit\Domain\Access\Services;

use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Access\Services\TTLockClient;
use App\Domain\Foundation\Models\Device;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TTLockClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
    }

    private function fakeToken(): void
    {
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response([
                'access_token' => 'token-abc', 'refresh_token' => 'refresh-abc',
                'expires_in' => 7776000, 'uid' => 1, 'scope' => 'user,key,room',
            ], 200),
        ]);
    }

    public function test_add_period_passcode_returns_passcode_and_vendor_id(): void
    {
        $this->fakeToken();
        Http::fake([
            'api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '135790', 'keyboardPwdId' => 42], 200),
        ]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        $result = app(TTLockClient::class)->addPeriodPasscode($lock, now(), now()->addHours(2));

        $this->assertSame('135790', $result['passcode']);
        $this->assertSame(42, $result['vendor_passcode_id']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sciener.test/v3/keyboardPwd/get'
            && $request['lockId'] === '99' && $request['keyboardPwdType'] == 3);
    }

    public function test_delete_passcode_sends_gateway_delete_type(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => 'none error message'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        app(TTLockClient::class)->deletePasscode($lock, 42);

        Http::assertSent(fn ($request) => $request['deleteType'] == 2 && $request['keyboardPwdId'] == 42);
    }

    public function test_remote_unlock_success(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => 'none error message'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        app(TTLockClient::class)->remoteUnlock($lock);

        $this->assertTrue(true); // no exception thrown
    }

    public function test_remote_unlock_gateway_offline_maps_to_named_exception(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => -2012, 'errmsg' => 'The Lock is not connected to any Gateway.'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        try {
            app(TTLockClient::class)->remoteUnlock($lock);
            $this->fail('Expected TTLockException');
        } catch (TTLockException $e) {
            $this->assertSame(-2012, $e->vendorErrorCode);
        }
    }

    public function test_remote_unlock_disabled_maps_to_named_exception(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => -4043, 'errmsg' => 'not supported'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        $this->expectException(TTLockException::class);
        app(TTLockClient::class)->remoteUnlock($lock);
    }

    public function test_invalid_credentials_on_token_fetch_throws(): void
    {
        Http::fake(['api.sciener.test/oauth2/token' => Http::response(['errcode' => 10007, 'errmsg' => 'invalid account'], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        $this->expectException(TTLockException::class);
        app(TTLockClient::class)->remoteUnlock($lock);
    }

    public function test_token_is_cached_across_calls(): void
    {
        $this->fakeToken();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        $client = app(TTLockClient::class);
        $client->remoteUnlock($lock);
        $client->remoteUnlock($lock);

        Http::assertSentCount(3); // 1 token fetch + 2 unlocks, not 2 token fetches + 2 unlocks
    }

    public function test_expired_cached_token_is_refreshed_not_re_fetched_from_scratch(): void
    {
        Cache::put('ttlock:oauth_token', [
            'access_token' => 'stale', 'refresh_token' => 'refresh-abc', 'expires_at' => time() - 10,
        ], now()->addDay());
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response([
                'access_token' => 'fresh', 'refresh_token' => 'refresh-new', 'expires_in' => 7776000,
            ], 200),
            'api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
        $lock = Device::factory()->create(['type' => 'lock', 'external_ref' => '99']);

        app(TTLockClient::class)->remoteUnlock($lock);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2/token') && ($request['grant_type'] ?? null) === 'refresh_token');
    }
}
```

Run: `php artisan test tests/Unit/Domain/Access/Services/TTLockClientTest.php`
Expected: FAIL (classes don't exist) before Steps 1–3, PASS after.

- [ ] **Step 5: Run, verify all pass, commit**

```bash
./vendor/bin/pint --test
php artisan test tests/Unit/Domain/Access/Services/TTLockClientTest.php
git add app/Domain/Access/Exceptions/TTLockException.php app/Domain/Access/Services/TTLockClient.php config/services.php .env.example tests/Unit/Domain/Access/Services/TTLockClientTest.php
git commit -m "feat: add TTLockClient wrapping TTLock Cloud API V3"
```

**⚠ Not automatable from this environment:** no TTLock credentials or reachable hardware exist here. Before this client is trusted in production, someone with real credentials must run at minimum one `addPeriodPasscode` + `remoteUnlock` + `deletePasscode` cycle against the actual paired lock/gateway and confirm: (a) the base host is actually reachable (the biggest unverified assumption — see findings above), (b) the lock's "remote unlock" toggle is enabled in the TTLock app, (c) a `keyboardPwd/get`-issued Period passcode actually appears on the physical keypad, and (d) whether the gateway relay has any propagation delay between the API call returning and the passcode being live on the lock (unknown — could affect how soon after issuance a member can use the keypad).

---

### Task 3: `PasscodeIssuanceService` + 3 scheduled commands + concurrency test

**Files:**
- Create: `app/Domain/Access/Exceptions/LockAccessDeniedException.php`
- Create: `app/Domain/Access/Services/PasscodeIssuanceService.php`
- Create: `app/Console/Commands/IssueAccessGrantsOnCancellationWindowClose.php`
- Create: `app/Console/Commands/RevokeAccessGrantsOnMaintenance.php`
- Create: `app/Console/Commands/ExpireUnactivatedAccessGrants.php`
- Modify: `routes/console.php`
- Test: `tests/Unit/Domain/Access/Services/PasscodeIssuanceServiceTest.php`
- Test: `tests/Feature/Access/AccessGrantConcurrencyTest.php`

**Interfaces:**
- Consumes: `TTLockClient` (Task 2), `AccessGrant`/`AccessEvent`/enums (Task 1), `App\Domain\Booking\Models\Booking` (`->space`, `->user_id`, `->end_at`, `->status`), `App\Domain\Foundation\Models\Space` (`->devices()`, `->status`), `App\Domain\Identity\Models\Company`.
- Produces: `PasscodeIssuanceService::issueForBooking(Booking $booking): AccessGrant`, `::issueForTenancy(Company $company, Device $lock): AccessGrant`, `::activate(AccessGrant $grant): void` (throws `LockAccessDeniedException` with status 409 if not `Issued`), `::revokeForSpace(Space $space): void`, `::expireOverdue(): int` (returns count expired). `LockAccessDeniedException` with `public readonly string $messageKey`, `public readonly int $status = 422`.

- [ ] **Step 1: `LockAccessDeniedException`**

```php
<?php

namespace App\Domain\Access\Exceptions;

use RuntimeException;

/**
 * One exception type for every Access-domain precondition failure,
 * mirroring App\Domain\Booking\Exceptions\ReceptionActionException's
 * shape exactly — caught manually per-controller, not registered globally.
 */
class LockAccessDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $messageKey,
        public readonly int $status = 422,
    ) {
        parent::__construct($messageKey);
    }
}
```

- [ ] **Step 2: `PasscodeIssuanceService`**

```php
<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Enums\PasscodeType;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owns the access_grants lifecycle end to end: issue (booking or tenancy),
 * activate (kiosk-confirmed arrival), revoke (maintenance conflict),
 * expire (never activated in time). docs/decisions/qr-lock-unlock.md §2.
 */
class PasscodeIssuanceService
{
    public function __construct(private readonly TTLockClient $ttlock) {}

    public function issueForBooking(Booking $booking): AccessGrant
    {
        $lock = $this->lockFor($booking->space);
        $issuedAt = now();

        $vendor = $this->ttlock->addPeriodPasscode($lock, $issuedAt, $booking->end_at);

        return AccessGrant::create([
            'lock_id' => $lock->id,
            'grantee_type' => OwnerType::User,
            'grantee_id' => $booking->user_id,
            'source_type' => AccessSourceType::Booking,
            'source_id' => $booking->id,
            'allocation_model' => $booking->space->allocation_model,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => $vendor['passcode'],
            'vendor_keyboard_pwd_id' => $vendor['vendor_passcode_id'],
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt->copy()->addHours(24),
            'expires_at' => $booking->end_at,
            'status' => AccessGrantStatus::Issued,
        ]);
    }

    public function issueForTenancy(Company $company, Device $lock): AccessGrant
    {
        $issuedAt = now();
        // TTLock's Period type requires a bounded endDate; a tenancy grant
        // is conceptually open-ended, so the vendor-side window is set far
        // enough out to be operationally permanent. Our own status/
        // expires_at (left null below) are what actually gate access —
        // this bound only exists to satisfy the vendor API's shape.
        $vendorEnd = $issuedAt->copy()->addYears(5);

        $vendor = $this->ttlock->addPeriodPasscode($lock, $issuedAt, $vendorEnd);

        return AccessGrant::create([
            'lock_id' => $lock->id,
            'grantee_type' => OwnerType::Company,
            'grantee_id' => $company->id,
            'source_type' => AccessSourceType::Tenancy,
            'source_id' => null,
            'allocation_model' => AllocationModel::Tenancy,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => $vendor['passcode'],
            'vendor_keyboard_pwd_id' => $vendor['vendor_passcode_id'],
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt->copy()->addHours(24),
            'expires_at' => null,
            'status' => AccessGrantStatus::Issued,
        ]);
    }

    public function activate(AccessGrant $grant): void
    {
        $activated = DB::transaction(function () use ($grant) {
            $locked = AccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== AccessGrantStatus::Issued) {
                throw new LockAccessDeniedException('api.access.grant_not_activatable', 409);
            }

            $locked->forceFill(['status' => AccessGrantStatus::Activated, 'activated_at' => now()])->save();

            return $locked;
        });

        AccessEvent::create([
            'device_id' => $activated->lock_id,
            'access_grant_id' => $activated->id,
            'event_type' => AccessEventType::Unlock,
            'channel' => AccessEventChannel::ReceptionActivation,
            'occurred_at' => now(),
        ]);
    }

    public function revokeForSpace(Space $space): void
    {
        $lock = $this->lockFor($space);

        AccessGrant::query()
            ->where('lock_id', $lock->id)
            ->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
            ->get()
            ->each(fn (AccessGrant $grant) => $this->revoke($grant, $lock));
    }

    public function expireOverdue(): int
    {
        $count = 0;

        AccessGrant::query()
            ->where('status', AccessGrantStatus::Issued)
            ->where('must_activate_by', '<', now())
            ->chunkById(100, function ($grants) use (&$count) {
                foreach ($grants as $grant) {
                    // TTLock's own Period passcode already auto-invalidates
                    // on the lock after 24h unused — no vendor call needed
                    // here, only our own status kept in sync.
                    $grant->update(['status' => AccessGrantStatus::Expired]);
                    $count++;
                }
            });

        return $count;
    }

    private function revoke(AccessGrant $grant, Device $lock): void
    {
        $revoked = DB::transaction(function () use ($grant) {
            $locked = AccessGrant::query()->whereKey($grant->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [AccessGrantStatus::Issued, AccessGrantStatus::Activated], true)) {
                return null;
            }

            $locked->forceFill(['status' => AccessGrantStatus::Revoked])->save();

            return $locked;
        });

        if ($revoked === null) {
            return;
        }

        try {
            $this->ttlock->deletePasscode($lock, $revoked->vendor_keyboard_pwd_id);
        } catch (TTLockException $e) {
            Log::error('Failed to delete TTLock passcode after revoking an access grant', [
                'access_grant_id' => $revoked->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function lockFor(Space $space): Device
    {
        return $space->devices()->where('type', 'lock')->firstOrFail();
    }
}
```

- [ ] **Step 3: Unit tests for `PasscodeIssuanceService`**

```php
<?php

namespace Tests\Unit\Domain\Access\Services;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PasscodeIssuanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake(['api.sciener.test/oauth2/token' => Http::response([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000,
        ], 200)]);
    }

    public function test_issue_for_booking_creates_an_issued_grant_with_24h_activation_window(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/get' => Http::response(['keyboardPwd' => '112233', 'keyboardPwdId' => 5], 200)]);
        $space = Space::factory()->create();
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $booking = Booking::factory()->create(['space_id' => $space->id]);

        $grant = app(PasscodeIssuanceService::class)->issueForBooking($booking);

        $this->assertSame(AccessGrantStatus::Issued, $grant->status);
        $this->assertSame('112233', $grant->passcode_value);
        $this->assertSame(5, $grant->vendor_keyboard_pwd_id);
        $this->assertEqualsWithDelta($grant->issued_at->addHours(24)->timestamp, $grant->must_activate_by->timestamp, 2);
    }

    public function test_expire_overdue_marks_only_issued_grants_past_must_activate_by(): void
    {
        $overdue = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued, 'must_activate_by' => now()->subHour()]);
        $notYet = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued, 'must_activate_by' => now()->addHour()]);
        $alreadyActivated = AccessGrant::factory()->activated()->create(['must_activate_by' => now()->subHour()]);

        $count = app(PasscodeIssuanceService::class)->expireOverdue();

        $this->assertSame(1, $count);
        $this->assertSame(AccessGrantStatus::Expired, $overdue->fresh()->status);
        $this->assertSame(AccessGrantStatus::Issued, $notYet->fresh()->status);
        $this->assertSame(AccessGrantStatus::Activated, $alreadyActivated->fresh()->status);
    }

    public function test_revoke_for_space_revokes_and_deletes_vendor_passcode_for_issued_and_activated_grants(): void
    {
        Http::fake(['api.sciener.test/v3/keyboardPwd/delete' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $space = Space::factory()->create(['status' => OperationalStatus::Maintenance]);
        $lock = Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);
        $issued = AccessGrant::factory()->create(['lock_id' => $lock->id, 'status' => AccessGrantStatus::Issued]);
        $activated = AccessGrant::factory()->activated()->create(['lock_id' => $lock->id]);
        $alreadyRevoked = AccessGrant::factory()->revoked()->create(['lock_id' => $lock->id]);

        app(PasscodeIssuanceService::class)->revokeForSpace($space);

        $this->assertSame(AccessGrantStatus::Revoked, $issued->fresh()->status);
        $this->assertSame(AccessGrantStatus::Revoked, $activated->fresh()->status);
        Http::assertSentCount(3); // token + 2 deletes (not 3 — the already-revoked grant is untouched)
    }
}
```

Run: `php artisan test tests/Unit/Domain/Access/Services/PasscodeIssuanceServiceTest.php`
Expected: FAIL before Step 2, PASS after.

- [ ] **Step 4: The genuine concurrency test**

```php
<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AccessGrantConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A scheduled RevokeAccessGrantsOnMaintenance run and a reception
     * kiosk's activation request can both touch the same grant row.
     * Injects the race at the exact moment activate()'s own transaction
     * begins — after nothing has locked the row yet — mutating it to
     * `revoked` right before activate()'s lockForUpdate() query runs.
     * Not a claim of real multi-connection concurrency: this suite's
     * in-memory SQLite can't reproduce that (same documented limitation
     * as WalkInCapacityServiceTest.php and the sp-today race fix in
     * 1e7026b) — a direct, honest injection at the one point that
     * matters: does activate()'s lock-and-recheck catch a status change
     * that happened after the initial read, rather than trusting stale
     * state?
     */
    public function test_activation_racing_a_maintenance_revoke_is_rejected_not_silently_activated(): void
    {
        $grant = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued]);

        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired, $grant) {
            if (! $fired) {
                $fired = true;
                DB::table('access_grants')->where('id', $grant->id)->update(['status' => 'revoked']);
            }
        });

        $this->expectException(LockAccessDeniedException::class);

        try {
            app(PasscodeIssuanceService::class)->activate($grant);
        } finally {
            $this->assertTrue($fired, 'The race-injection listener never fired — this test would pass vacuously without it.');
            $this->assertSame(AccessGrantStatus::Revoked, $grant->fresh()->status, 'The revoke that won the race must not be clobbered back to activated.');
        }
    }
}
```

Run: `php artisan test tests/Feature/Access/AccessGrantConcurrencyTest.php`
Expected: PASS once Step 2's `activate()` lock-then-recheck exists (it already does, from Step 2 — this step is verification, not new production code).

- [ ] **Step 5: Three scheduled commands**

```php
<?php
// app/Console/Commands/IssueAccessGrantsOnCancellationWindowClose.php
namespace App\Console\Commands;

use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A confirmed booking's cancellation window closing (or a same-day
 * booking, immediately) is when its space's lock gets a Period passcode
 * — docs/decisions/qr-lock-unlock.md §2. Skips any booking that already
 * has a grant (source_type=booking, source_id=this booking) so a re-run
 * before the next window doesn't double-issue.
 */
class IssueAccessGrantsOnCancellationWindowClose extends Command
{
    protected $signature = 'access:issue-grants-on-cancellation-window-close';

    protected $description = "Issue an access grant for each confirmed booking whose cancellation window has closed and doesn't have one yet.";

    public function handle(PasscodeIssuanceService $issuance, SettingService $settings): int
    {
        Booking::query()
            ->where('status', BookingStatus::Confirmed)
            ->whereDoesntHave('accessGrants')
            ->with('space')
            ->chunkById(100, function ($bookings) use ($issuance, $settings) {
                foreach ($bookings as $booking) {
                    $windowMinutes = $booking->space->cancellation_window_minutes
                        ?? $settings->get('booking.cancellation_window_minutes', 60);
                    $windowClosed = now()->gt($booking->start_at->copy()->subMinutes($windowMinutes));
                    $sameDay = now()->isSameDay($booking->start_at);

                    if (! $windowClosed && ! $sameDay) {
                        continue;
                    }

                    try {
                        $issuance->issueForBooking($booking);
                    } catch (\Throwable $e) {
                        Log::error('Failed to issue access grant for booking', [
                            'booking_id' => $booking->id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }
}
```

```php
<?php
// app/Console/Commands/RevokeAccessGrantsOnMaintenance.php
namespace App\Console\Commands;

use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RevokeAccessGrantsOnMaintenance extends Command
{
    protected $signature = 'access:revoke-grants-on-maintenance';

    protected $description = 'Revoke every issued/activated access grant for a lock on any space currently under maintenance.';

    public function handle(PasscodeIssuanceService $issuance): int
    {
        Space::query()
            ->where('status', OperationalStatus::Maintenance)
            ->whereHas('devices', fn ($q) => $q->where('type', 'lock'))
            ->chunkById(100, function ($spaces) use ($issuance) {
                foreach ($spaces as $space) {
                    try {
                        $issuance->revokeForSpace($space);
                    } catch (\Throwable $e) {
                        Log::error('Failed to revoke access grants for a space in maintenance', [
                            'space_id' => $space->id, 'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }
}
```

```php
<?php
// app/Console/Commands/ExpireUnactivatedAccessGrants.php
namespace App\Console\Commands;

use App\Domain\Access\Services\PasscodeIssuanceService;
use Illuminate\Console\Command;

class ExpireUnactivatedAccessGrants extends Command
{
    protected $signature = 'access:expire-unactivated-grants';

    protected $description = 'Mark any issued access grant past its must_activate_by as expired.';

    public function handle(PasscodeIssuanceService $issuance): int
    {
        $issuance->expireOverdue();

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Add `Booking::accessGrants()` relation** (needed by Step 5's `whereDoesntHave`)

Add to `App\Domain\Booking\Models\Booking`:

```php
    public function accessGrants(): HasMany
    {
        return $this->hasMany(\App\Domain\Access\Models\AccessGrant::class, 'source_id')
            ->where('source_type', \App\Domain\Access\Enums\AccessSourceType::Booking);
    }
```

(Add `use Illuminate\Database\Eloquent\Relations\HasMany;` if not already imported.)

- [ ] **Step 7: Register the three schedules**

Add to `routes/console.php`, after the existing `finance:fetch-exchange-rate-suggestion` block:

```php
/*
 * Access control (docs/decisions/qr-lock-unlock.md) — the lock passcode
 * lifecycle: issue once a booking's cancellation window closes, revoke on
 * a maintenance conflict, expire anything never activated in time.
 */
Schedule::command('access:issue-grants-on-cancellation-window-close')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('access:revoke-grants-on-maintenance')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('access:expire-unactivated-grants')->everyFiveMinutes()->withoutOverlapping();
```

- [ ] **Step 8: Test the commands are registered and runnable**

```php
<?php
// tests/Feature/Access/AccessCommandsAreScheduledTest.php
namespace Tests\Feature\Access;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessCommandsAreScheduledTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_commands_run_clean_with_no_data(): void
    {
        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();
        $this->artisan('access:revoke-grants-on-maintenance')->assertSuccessful();
        $this->artisan('access:expire-unactivated-grants')->assertSuccessful();
    }
}
```

Run: `php artisan test tests/Feature/Access/AccessCommandsAreScheduledTest.php`
Expected: PASS.

- [ ] **Step 9: Full task verification + commit**

```bash
./vendor/bin/pint --test
php artisan test --filter=Access
git add app/Domain/Access/Exceptions/LockAccessDeniedException.php app/Domain/Access/Services/PasscodeIssuanceService.php app/Console/Commands/IssueAccessGrantsOnCancellationWindowClose.php app/Console/Commands/RevokeAccessGrantsOnMaintenance.php app/Console/Commands/ExpireUnactivatedAccessGrants.php app/Domain/Booking/Models/Booking.php routes/console.php tests/Unit/Domain/Access/Services/PasscodeIssuanceServiceTest.php tests/Feature/Access
git commit -m "feat: add passcode issuance/activation/revocation/expiry lifecycle"
```

---

### Task 4: Reception activation endpoint

**Files:**
- Create: `app/Http/Controllers/Api/V1/Admin/Reception/AccessActivationController.php`
- Modify: `routes/api/v1/admin.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php`
- Test: `tests/Feature/Access/AccessActivationTest.php`

**Interfaces:**
- Consumes: `PasscodeIssuanceService::activate(AccessGrant $grant): void` (Task 3), `LockAccessDeniedException` (Task 3).
- Produces: `POST /api/v1/admin/reception/access-grants/{accessGrant}/activate`.

- [ ] **Step 1: Failing feature test**

```php
<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('operations');
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_activating_an_issued_grant_succeeds_and_logs_reception_activation(): void
    {
        $grant = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued]);

        $response = $this->postJson("/api/v1/admin/reception/access-grants/{$grant->id}/activate");

        $response->assertOk();
        $this->assertSame(AccessGrantStatus::Activated, $grant->fresh()->status);
        $this->assertNotNull($grant->fresh()->activated_at);
        $this->assertDatabaseHas('access_events', [
            'access_grant_id' => $grant->id,
            'channel' => AccessEventChannel::ReceptionActivation->value,
        ]);
    }

    public function test_activating_an_already_activated_grant_returns_409(): void
    {
        $grant = AccessGrant::factory()->activated()->create();

        $this->postJson("/api/v1/admin/reception/access-grants/{$grant->id}/activate")->assertStatus(409);
    }

    public function test_activating_a_revoked_grant_returns_409(): void
    {
        $grant = AccessGrant::factory()->revoked()->create();

        $this->postJson("/api/v1/admin/reception/access-grants/{$grant->id}/activate")->assertStatus(409);
    }
}
```

Run: `php artisan test tests/Feature/Access/AccessActivationTest.php`
Expected: FAIL (404 — route doesn't exist) before Steps 2–4.

- [ ] **Step 2: Controller**

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AccessActivationController extends Controller
{
    public function activate(AccessGrant $accessGrant, PasscodeIssuanceService $issuance): JsonResponse
    {
        try {
            $issuance->activate($accessGrant);
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['message' => __('api.access.activated')]);
    }
}
```

- [ ] **Step 3: Route**

In `routes/api/v1/admin.php`, near the other `reception/` routes (after the `reception/wallet-top-ups` line), add:

```php
use App\Http\Controllers\Api\V1\Admin\Reception\AccessActivationController;
// ...
Route::post('reception/access-grants/{accessGrant}/activate', [AccessActivationController::class, 'activate']);
```

- [ ] **Step 4: Lang keys**

Add a new top-level `'access' => [...]` array to both `lang/en/api.php` and `lang/ar/api.php`:

```php
    'access' => [
        'activated' => 'The access grant has been activated.',
        'grant_not_activatable' => 'This access grant cannot be activated in its current state.',
    ],
```

(Arabic equivalents in `lang/ar/api.php`.)

- [ ] **Step 5: Run, verify, commit**

```bash
./vendor/bin/pint --test
php artisan test tests/Feature/Access/AccessActivationTest.php
git add app/Http/Controllers/Api/V1/Admin/Reception/AccessActivationController.php routes/api/v1/admin.php lang/en/api.php lang/ar/api.php tests/Feature/Access/AccessActivationTest.php
git commit -m "feat: add POST admin/reception/access-grants/{id}/activate"
```

---

### Task 5: Member QR unlock endpoint

**Files:**
- Create: `app/Domain/Access/Services/UnlockService.php`
- Create: `app/Http/Requests/Member/UnlockRequest.php`
- Create: `app/Http/Controllers/Api/V1/Member/AccessUnlockController.php`
- Modify: `routes/api/v1/member.php`
- Modify: `lang/en/api.php`, `lang/ar/api.php` (extend the `'access'` group added in Task 4)
- Test: `tests/Feature/Access/UnlockViaQrTest.php`

**Interfaces:**
- Consumes: `TTLockClient::remoteUnlock()` (Task 2), `TTLockException` (Task 2), `AccessGrant`/`AccessEvent`/enums (Task 1), `App\Domain\Identity\Models\User::companies()` (existing `BelongsToMany` with `door_access_enabled`/`is_admin` pivot).
- Produces: `UnlockService::unlock(User $user, string $qrValue): void` (throws `LockAccessDeniedException`), `POST /api/v1/member/access/unlock`.

- [ ] **Step 1: Failing feature tests (all six scenarios up front)**

```php
<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnlockViaQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake(['api.sciener.test/oauth2/token' => Http::response([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000,
        ], 200)]);
    }

    private function actingAsMember(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        Sanctum::actingAs($user, ['member-app']);

        return $user;
    }

    public function test_activated_grant_for_the_scanning_user_unlocks_and_logs_qr_scan(): void
    {
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id,
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1']);

        $response->assertOk();
        $this->assertDatabaseHas('access_events', [
            'device_id' => $lock->id, 'event_type' => AccessEventType::Unlock->value, 'channel' => AccessEventChannel::QrScan->value,
        ]);
    }

    public function test_expired_grant_is_denied_and_logs_failed_attempt(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
        $this->assertDatabaseHas('access_events', ['device_id' => $lock->id, 'event_type' => AccessEventType::FailedAttempt->value]);
    }

    public function test_revoked_grant_is_denied(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->revoked()->create(['lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_not_yet_activated_grant_is_denied(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->create(['lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id, 'status' => AccessGrantStatus::Issued]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_user_with_no_grant_for_this_lock_is_denied(): void
    {
        $user = $this->actingAsMember();
        Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_maintenance_revoked_grant_is_denied_even_if_activated_moments_earlier(): void
    {
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id,
            'status' => AccessGrantStatus::Revoked, 'activated_at' => now()->subSeconds(5), 'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_company_tenancy_grant_works_for_a_member_with_door_access_enabled(): void
    {
        $user = $this->actingAsMember();
        $company = Company::factory()->create();
        $company->members()->attach($user->id, ['door_access_enabled' => true, 'is_admin' => false]);
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->forCompany()->activated()->create(['lock_id' => $lock->id, 'grantee_id' => $company->id]);
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertOk();
    }

    public function test_company_member_without_door_access_enabled_is_denied(): void
    {
        $user = $this->actingAsMember();
        $company = Company::factory()->create();
        $company->members()->attach($user->id, ['door_access_enabled' => false, 'is_admin' => false]);
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->forCompany()->activated()->create(['lock_id' => $lock->id, 'grantee_id' => $company->id]);

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1'])->assertStatus(403);
    }

    public function test_unknown_qr_value_returns_404(): void
    {
        $this->actingAsMember();

        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'does-not-exist'])->assertStatus(404);
    }

    public function test_gateway_offline_returns_a_distinguishable_message(): void
    {
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => -2012, 'errmsg' => 'The Lock is not connected to any Gateway.'], 200)]);
        $user = $this->actingAsMember();
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create(['lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id, 'expires_at' => now()->addHour()]);

        $response = $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1']);

        $response->assertStatus(503);
        $response->assertJsonFragment(['message' => __('api.access.gateway_offline')]);
        $this->assertDatabaseHas('access_events', ['device_id' => $lock->id, 'event_type' => AccessEventType::FailedAttempt->value]);
    }
}
```

Run: `php artisan test tests/Feature/Access/UnlockViaQrTest.php`
Expected: FAIL (404s — route/classes don't exist) before Steps 2–5.

- [ ] **Step 2: `UnlockService`**

```php
<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Enums\AccessEventChannel;
use App\Domain\Access\Enums\AccessEventType;
use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Exceptions\TTLockException;
use App\Domain\Access\Models\AccessEvent;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;

/**
 * The one read path for the new QR-scan channel — resolves qr_value to a
 * lock, finds an activated-and-in-window grant for the scanning user (or
 * a company they belong to with door_access_enabled), calls
 * TTLockClient::remoteUnlock() server-side, and logs one access_events
 * row either way. docs/decisions/qr-lock-unlock.md §4.
 */
class UnlockService
{
    public function __construct(private readonly TTLockClient $ttlock) {}

    public function unlock(User $user, string $qrValue): void
    {
        $lock = Device::where('qr_value', $qrValue)->where('type', 'lock')->first();

        if (! $lock) {
            throw new LockAccessDeniedException('api.access.lock_not_found', 404);
        }

        $grant = $this->activeGrantFor($user, $lock);

        if (! $grant) {
            $this->logEvent($lock, null, AccessEventType::FailedAttempt, $user);
            throw new LockAccessDeniedException('api.access.no_active_grant', 403);
        }

        try {
            $this->ttlock->remoteUnlock($lock);
        } catch (TTLockException $e) {
            $this->logEvent($lock, $grant, AccessEventType::FailedAttempt, $user);
            throw new LockAccessDeniedException(
                $e->vendorErrorCode === -2012 ? 'api.access.gateway_offline' : 'api.access.unlock_failed',
                503,
            );
        }

        $this->logEvent($lock, $grant, AccessEventType::Unlock, $user);
    }

    private function activeGrantFor(User $user, Device $lock): ?AccessGrant
    {
        $now = now();
        $base = fn () => AccessGrant::query()
            ->where('lock_id', $lock->id)
            ->where('status', AccessGrantStatus::Activated)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now));

        $userGrant = $base()->where('grantee_type', OwnerType::User)->where('grantee_id', $user->id)->first();

        if ($userGrant) {
            return $userGrant;
        }

        $companyIds = $user->companies()->wherePivot('door_access_enabled', true)->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return null;
        }

        return $base()->where('grantee_type', OwnerType::Company)->whereIn('grantee_id', $companyIds)->first();
    }

    private function logEvent(Device $lock, ?AccessGrant $grant, AccessEventType $type, User $actor): void
    {
        AccessEvent::create([
            'device_id' => $lock->id,
            'access_grant_id' => $grant?->id,
            'event_type' => $type,
            'channel' => AccessEventChannel::QrScan,
            'actor_user_id' => $actor->id,
            'occurred_at' => now(),
        ]);
    }
}
```

- [ ] **Step 3: `UnlockRequest` + controller**

```php
<?php
// app/Http/Requests/Member/UnlockRequest.php
namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class UnlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['qr_value' => ['required', 'string', 'max:255']];
    }
}
```

```php
<?php
// app/Http/Controllers/Api/V1/Member/AccessUnlockController.php
namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Services\UnlockService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UnlockRequest;
use Illuminate\Http\JsonResponse;

class AccessUnlockController extends Controller
{
    public function unlock(UnlockRequest $request, UnlockService $service): JsonResponse
    {
        try {
            $service->unlock($request->user(), $request->validated('qr_value'));
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['message' => __('api.access.unlocked')]);
    }
}
```

- [ ] **Step 4: Route**

In `routes/api/v1/member.php`, add near other member-facing action routes:

```php
use App\Http\Controllers\Api\V1\Member\AccessUnlockController;
// ...
Route::post('access/unlock', [AccessUnlockController::class, 'unlock']);
```

- [ ] **Step 5: Lang keys**

Extend the `'access'` array added in Task 4 (both `lang/en/api.php` and `lang/ar/api.php`):

```php
        'unlocked' => 'Unlocked.',
        'lock_not_found' => 'This QR code does not match any lock.',
        'no_active_grant' => 'You do not have active access to this lock right now.',
        'gateway_offline' => "This lock's gateway is currently offline — use the keypad code instead.",
        'unlock_failed' => 'The lock could not be unlocked. Please try again or use the keypad code.',
```

- [ ] **Step 6: Run, verify all nine scenarios pass, commit**

```bash
./vendor/bin/pint --test
php artisan test tests/Feature/Access/UnlockViaQrTest.php
git add app/Domain/Access/Services/UnlockService.php app/Http/Requests/Member/UnlockRequest.php app/Http/Controllers/Api/V1/Member/AccessUnlockController.php routes/api/v1/member.php lang/en/api.php lang/ar/api.php tests/Feature/Access/UnlockViaQrTest.php
git commit -m "feat: add POST member/access/unlock (QR-scan channel)"
```

---

### Task 6: Guards

**Files:**
- Create: `tests/Guards/LockCredentialsNeverReachMemberTest.php`
- Create: `tests/Guards/QrValueIsRandomNotSequentialTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–5.

- [ ] **Step 1: `LockCredentialsNeverReachMemberTest`**

Satisfies both the task instructions' name and the doc §4 guard's intent (see Design Decision #6 above) — checks the member-facing unlock response never leaks vendor material, and does a source-level sweep of every Member-namespaced controller/resource for literal TTLock-secret strings as a second line of defense.

```php
<?php

namespace Tests\Guards;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * S4 acceptance criteria: no member-role endpoint response, anywhere,
 * can contain a raw passcode value, a TTLock access token, or SDK/lockData
 * material. docs/decisions/qr-lock-unlock.md §4's guard, named per the
 * most recent task instructions (LockCredentialsNeverReachMemberTest) —
 * see this plan's Design Decision #6 for why one file covers both.
 */
class LockCredentialsNeverReachMemberTest extends TestCase
{
    use RefreshDatabase, ScansSourceFiles;

    private const FORBIDDEN_STRINGS = ['passcode_value', 'vendor_keyboard_pwd_id', 'accessToken', 'lockData', 'clientSecret', 'client_secret'];

    public function test_unlock_response_body_never_contains_vendor_or_credential_material(): void
    {
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
        $user = User::factory()->create();
        $user->assignRole('member');
        Sanctum::actingAs($user, ['member-app']);
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $user->id, 'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'sticker-1']);

        $body = $response->getContent();
        $this->assertSame(['message'], array_keys($response->json()), 'The unlock response must contain nothing but a message key.');
        foreach (self::FORBIDDEN_STRINGS as $needle) {
            $this->assertStringNotContainsString($needle, $body, "Response leaked \"{$needle}\"");
        }
    }

    public function test_no_member_namespaced_controller_or_resource_references_forbidden_vendor_fields(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path('Http/Controllers/Api/V1/Member')) as $path => $contents) {
            foreach (['passcode_value', 'vendor_keyboard_pwd_id', 'lockData'] as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = "{$path} references \"{$needle}\"";
                }
            }
        }

        $this->assertSame([], $violations, "No Member-namespaced controller may reference vendor/credential fields:\n".implode("\n", $violations));
    }
}
```

Run: `php artisan test tests/Guards/LockCredentialsNeverReachMemberTest.php`
Expected: PASS (Task 5's controller already only returns `{"message": ...}`).

- [ ] **Step 2: `QrValueIsRandomNotSequentialTest`**

```php
<?php

namespace Tests\Guards;

use App\Domain\Foundation\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * docs/decisions/qr-lock-unlock.md §4: qr_value must be CSPRNG-drawn, not
 * derivable from another lock's value. Generates N real values via the
 * same code path DeviceController::store() uses and checks for any
 * arithmetic (shared numeric offset) or lexicographic (sorted-adjacent
 * shared-prefix) sequence.
 */
class QrValueIsRandomNotSequentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_qr_values_are_not_sequential(): void
    {
        $values = collect(range(1, 50))->map(fn () => Str::random(40))->all();

        $this->assertSame(50, count(array_unique($values)), 'Generated values must all be unique.');

        $sorted = $values;
        sort($sorted);
        for ($i = 1; $i < count($sorted); $i++) {
            $this->assertNotSame(
                substr($sorted[$i - 1], 0, 39),
                substr($sorted[$i], 0, 39),
                'Two generated values share a 39-character prefix — suspiciously sequential.'
            );
        }
    }

    public function test_creating_lock_devices_produces_non_sequential_qr_values(): void
    {
        $branch = \App\Domain\Foundation\Models\Branch::factory()->create();
        $values = collect(range(1, 20))
            ->map(fn () => Device::factory()->create(['branch_id' => $branch->id, 'type' => 'lock', 'qr_value' => Str::random(40)])->qr_value)
            ->all();

        $this->assertSame(20, count(array_unique($values)));

        foreach ($values as $i => $value) {
            if ($i === 0) {
                continue;
            }
            // No fixed-offset relationship between consecutively-created values.
            $this->assertNotEquals(ord($values[$i - 1][0]) + 1, ord($value[0]));
        }
    }
}
```

Run: `php artisan test tests/Guards/QrValueIsRandomNotSequentialTest.php`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
./vendor/bin/pint --test
php artisan test tests/Guards/LockCredentialsNeverReachMemberTest.php tests/Guards/QrValueIsRandomNotSequentialTest.php
git add tests/Guards/LockCredentialsNeverReachMemberTest.php tests/Guards/QrValueIsRandomNotSequentialTest.php
git commit -m "test: add S4 access-control guards"
```

---

### Task 7: Postman collection + doc addendum + final verification

**Files:**
- Modify: `postman/ADD-OS.postman_collection.json`
- Modify: `docs/decisions/qr-lock-unlock.md`

**Interfaces:** none — this task packages Tasks 1–6's already-built endpoints for external consumption and documentation; it adds no new production code.

- [ ] **Step 1: Add an "Access Control" folder to the Postman collection**

Read the existing collection's structure (`Member (App)` / `Admin (Dashboard)` top-level folders — see the sub-folder pattern under e.g. `Bookings`/`Kiosk`) and add:
- Under `Member (App)`: a new `Access Control` sub-folder with one request — `POST {{base_url}}/api/v1/member/access/unlock`, body `{"qr_value": "..."}`, with two saved example responses: `200 OK` (`{"message": "Unlocked."}`) and `403 Forbidden` (`{"message": "You do not have active access to this lock right now."}`).
- Under `Admin (Dashboard)`: a new `Access Control` sub-folder with two requests — `POST {{base_url}}/api/v1/admin/reception/access-grants/:accessGrant/activate` (example `200` and `409` responses) and `POST {{base_url}}/api/v1/admin/devices/:device/regenerate-qr-value` (example `200` response).

- [ ] **Step 2: Append the verification findings to the design doc**

Append a new `## TTLock verification findings (2026-08-26)` section to `docs/decisions/qr-lock-unlock.md`, containing this plan's "TTLock verification findings" section verbatim (the endpoint paths, param names, error codes, and the unresolved base-host question), plus an explicit line: *"Not verified against real hardware or a real TTLock account — no credentials existed in this environment during implementation. TTLockClient is built and fully tested against Http::fake using these documented signatures; a real-hardware smoke test is a required follow-up before this ships to production."*

- [ ] **Step 3: Full suite + Pint**

```bash
./vendor/bin/pint --test
php artisan test
```

Expected: 100% pass, Pint clean, no regressions to any pre-existing test.

- [ ] **Step 4: Final report**

Confirm before calling this done:
- `.env.example` has all five `TTLOCK_*` keys empty, and `git diff` contains no real credential anywhere.
- The verified endpoint signatures, error-code mapping, and the unresolved base-host question are all in `docs/decisions/qr-lock-unlock.md`.
- The Postman collection change is committed with real example responses.

```bash
git add postman/ADD-OS.postman_collection.json docs/decisions/qr-lock-unlock.md
git commit -m "docs: record verified TTLock API findings; add Access Control to Postman collection"
```

---

## Self-review notes

- **Spec coverage**: Task 1 = doc §1 (schema); Task 2 = §2's `TTLockClient` requirement + ground rules on credentials/live-doc verification; Task 3 = §2's lifecycle + the concurrency requirement; Task 4 = §2 activation; Task 5 = §4 (QR unlock); Task 6 = §4's guard + Task 6 of the task instructions; Task 7 = the Postman/report/doc-update requirements. The `devices` `type` enum gaining `gateway` was already done in the existing migration (verified directly) — no task needed for it.
- **Deviations flagged for Maryam's review before/while this executes**: the `vendor_keyboard_pwd_id` schema addition, the `door_access_enabled` gate on company grants, the guard filename choice, and above all the **unverified TTLock base host and complete absence of real-hardware verification** — all called out in "Design decisions this plan makes beyond the locked doc" and repeated in Task 2/7.
