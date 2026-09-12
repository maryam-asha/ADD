# ADD Point — Spec A: Foundation

**Status:** design approved 2026-09-12. **Owner:** Maryam Asha.
**Parent:** [2026-09-12-add-point-system-design.md](2026-09-12-add-point-system-design.md)
— read it first; its five invariants govern everything below.

**Behaviour change: none.** This spec adds a setting and a service and
nothing reads them yet. That is deliberate — it ships and bakes before
spec B changes what the API reports.

## Goal

One setting, one service, and the guard tests that keep both honest.

## 1. The setting

Seeded in `SettingSeeder` alongside the existing keys:

```php
$settings->setDefault('point.rate_usd', '0.10', SettingValueType::String);
```

**Why `String` and not `Int`.** `SettingValueType::Int` casts via
`(int) $value`, which turns `0.10` into `0` — silently zeroing the
economy. There is no `Decimal` case in `SettingValueType`, and adding
one would touch the settings domain for a single consumer. `String`
round-trips the exact decimal text, which is precisely what bcmath
wants as input.

**Why not `config/points.php`.** The brief requires admin control
("التحكم بها من إعدادات الموقع"), and `config/` is deploy-time only.
The settings store already provides an admin endpoint, per-key caching,
and an audit trail.

### Validation

`UpdateSettingRequest` currently maps `SettingValueType::String` to
`['required', 'string']`, which would accept `"banana"` as a rate. Add a
key-specific branch beside the existing `app.timezone` one:

```php
if ($this->targetSetting()->key === 'point.rate_usd') {
    return ['value' => ['required', 'numeric', 'gt:0', 'decimal:0,4']];
}
```

`gt:0` matters: a zero or negative rate would make every price free or
inverted. `decimal:0,4` caps precision at four places, matching the
`point_rate_usd` audit column in spec C.

### Audit

The settings update path already writes to the sensitive-action log.
Confirm `point.rate_usd` edits appear there — per the parent spec's
Risk 3, this single value reprices the whole economy, so the "who
changed it, from what, to what" trail is not optional.

## 2. `PointConversionService`

New: `app/Domain/Finance/Services/PointConversionService.php`.

Placed in `Finance` (not `Membership`) because it is called from
pricing, booking, and wallet alike — the same reasoning that put
`CurrencyConversionService` there.

### Public surface

```php
public function rate(): string;                        // '0.10', from settings
public function pointsToUsd(int $points): string;      // exact, no rounding
public function usdToPoints(string $usd): int;         // floor  — credits
public function usdToPointsCeil(string $usd): int;     // ceil   — debits
public function pointsToCurrency(int $points, string $currencyCode): ?string;
```

### Semantics

- **`pointsToUsd`** — `bcmul($points, $rate, 2)`. Pure display; never
  rounds to points, so it cannot lose value.
- **`usdToPoints`** — `bcdiv($usd, $rate, 0)`. bcdiv with scale 0
  truncates toward zero, which for the non-negative amounts this takes
  *is* floor. Used for every **credit** (Invariant 2).
- **`usdToPointsCeil`** — divides at scale 4, then rounds up if there is
  any remainder. Used for every **debit**.
- **`pointsToCurrency`** — composes `pointsToUsd` with
  `CurrencyConversionService::convert()`. Returns `null` when no
  exchange rate exists, matching the existing service's contract so
  callers keep their existing null-handling.

⚠️ **`usdToPointsCeil` must not be written as `ceil((float) $usd /
(float) $rate)`.** That is a float operation on money, and at the
boundary (e.g. exactly 18.0 points) float error can push a value up a
whole point. Implement it in bcmath:

```php
$quotient = bcdiv($usd, $this->rate(), 4);
$floor    = bcdiv($quotient, '1', 0);

return (int) (bccomp($quotient, $floor, 4) > 0 ? bcadd($floor, '1', 0) : $floor);
}
```

### Caching

`SettingService::get()` already caches forever and invalidates on write,
so `rate()` calls it directly without adding a second cache layer. A
second layer would be a second invalidation bug waiting to happen.

### Negative and zero input

`pointsToUsd` accepts negative point counts — the ledger stores debits
as negative integers, and a refund display needs them. `usdToPoints`
and `usdToPointsCeil` reject negative input with
`InvalidArgumentException`: a negative credit is a caller bug, and the
floor/ceil asymmetry is only correct for non-negative values.

## 3. Guard tests

Three, in `tests/Guards/`, following the existing convention that a
locked decision gets an executable check rather than prose.

**`PointRateIsNeverFloatTest`** — scans `app/` for float casts or float
arithmetic applied to the rate: `(float)` adjacent to `point.rate_usd`,
and `/` or `*` operators on the result of `rate()`. Enforces Invariant
5 and the `usdToPointsCeil` warning above.

**`PointConversionIsCentralizedTest`** — scans `app/` for any file other
than `PointConversionService` that reads `point.rate_usd`. Enforces
Invariant 4. Spec B and C will both be tempted to inline a conversion;
this fails the build when they do.

**`PointRoundingFavoursTheHouseTest`** — a behavioural test, not a
scanner. Asserts `usdToPoints('34.09')` is `340` and
`usdToPointsCeil('0.74')` is `8` at a rate of `0.10`, plus the exact
boundary: `usdToPointsCeil('1.80')` is exactly `18`, not `19`. That
boundary case is the one a float implementation gets wrong.

## 4. Unit tests

Beyond the guards, `tests/Unit/Finance/PointConversionServiceTest.php`:

| Case | Expectation |
|---|---|
| Rate reads from settings | `rate()` returns the seeded `'0.10'` |
| Admin edit takes effect | After `set('point.rate_usd','0.12')`, `pointsToUsd(100)` is `'12.00'` |
| Revaluation | Same point count, different rate, different USD — Invariant 3 |
| Credit floors | `usdToPoints('34.09')` → `340` |
| Debit ceils | `usdToPointsCeil('0.71')` → `8` |
| Exact boundary | `usdToPointsCeil('1.80')` → `18` |
| Negative rejected | `usdToPoints('-5.00')` throws |
| Missing FX rate | `pointsToCurrency(100, 'EUR')` → `null` when no rate row |
| SYP round trip | `pointsToCurrency(340, 'SYP')` ≈ `500000` at the seeded rate |

## Files touched

| File | Change |
|---|---|
| `database/seeders/SettingSeeder.php` | +1 `setDefault` line |
| `app/Http/Requests/Admin/UpdateSettingRequest.php` | +1 validation branch |
| `app/Domain/Finance/Services/PointConversionService.php` | New |
| `tests/Guards/PointRateIsNeverFloatTest.php` | New |
| `tests/Guards/PointConversionIsCentralizedTest.php` | New |
| `tests/Guards/PointRoundingFavoursTheHouseTest.php` | New |
| `tests/Unit/Finance/PointConversionServiceTest.php` | New |

No migration. No controller. No route.

## Done when

`php artisan test` passes, `./vendor/bin/pint --test` is clean, the
setting is visible and editable through the existing admin settings
endpoint, and the three guards fail if their rule is violated (verify by
temporarily breaking each one).

## Explicitly not done here

- No consumer reads `PointConversionService` yet — that is spec B.
- No schema change of any kind.
- No dashboard work; the setting appears automatically in the existing
  settings list.
