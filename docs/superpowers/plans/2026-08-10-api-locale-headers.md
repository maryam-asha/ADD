# API-wide locale via `lang` header — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every JSON message this API returns — success, validation, business-rule rejection, rate-limit, generic HTTP error — is translated according to a `lang: ar|en` request header, falling back silently to the authenticated user's `preferred_language` and then to `'ar'`.

**Architecture:** A middleware (`SetLocaleFromHeader`) reads the header and sets a provisional locale before anything else runs. An event listener (`SetLocaleFromUserPreference`), hooked to the same two auth-resolution events `EnsureAuthenticatedUserIsActive` already uses, corrects that provisional value to the authenticated user's stored preference — but only when no valid header was sent. Every hardcoded message literal moves into `lang/{ar,en}/api.php`, called via `__()`. Six `$exceptions->render()` closures added to `bootstrap/app.php` translate `ValidationException`, `ThrottleRequestsException`, `AuthenticationException`, `AuthorizationException`, `NotFoundHttpException`, and the generic uncaught-exception fallback.

**Tech Stack:** Laravel 12, PHPUnit (class-based, `test_*` methods — not Pest), Sanctum, Spatie Permission.

## Global Constraints

- Header name is exactly `lang`, values `ar`/`en`, case-insensitive. No other value, and no absent header, may ever produce a 4xx by itself.
- A valid header always overrides `preferred_language`, unconditionally.
- Translation files live at `lang/{ar,en}/...` (**not** `resources/lang/` — confirmed empirically: this Laravel 12 project has no `resources/lang/` directory, and `Application::langPath()` resolves to `base_path('lang')` since neither existed before this work).
- No literal Arabic or English message text may remain in `app/` after this plan — every user-facing message routes through `__('api.<domain>.<key>')`.
- Error response shape stays `{message, errors?}` — no envelope change.
- `composer test` must be run after every task, and the plan's final task confirms the total test count against the pre-existing baseline plus the new tests this plan adds.
- Full spec: `docs/superpowers/specs/2026-08-10-api-locale-headers-design.md`.

---

## Task 1: Arabic + English `validation.php`

**Files:**
- Create: `lang/en/validation.php`
- Create: `lang/ar/validation.php`
- Test: `tests/Unit/Lang/ValidationTranslationTest.php`

**Interfaces:**
- Consumes: nothing (no dependency on earlier tasks).
- Produces: standard Laravel `validation.*` keys, consumed automatically by `ValidationException` — Task 4's `ValidationException` render() relies on these existing for the `errors` bag to be translated.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Lang;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class ValidationTranslationTest extends TestCase
{
    public function test_the_required_rule_message_is_in_english_by_default(): void
    {
        App::setLocale('en');

        $this->assertSame(
            'The name field is required.',
            trans('validation.required', ['attribute' => 'name'])
        );
    }

    public function test_the_required_rule_message_is_translated_to_arabic(): void
    {
        App::setLocale('ar');

        $this->assertSame(
            'حقل الاسم مطلوب.',
            trans('validation.required', ['attribute' => 'الاسم'])
        );
    }

    public function test_the_confirmed_rule_message_is_translated_to_arabic(): void
    {
        App::setLocale('ar');

        $this->assertSame(
            'تأكيد حقل كلمة المرور غير مطابق.',
            trans('validation.confirmed', ['attribute' => 'كلمة المرور'])
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Lang/ValidationTranslationTest.php`
Expected: FAIL — `trans('validation.required', ...)` returns the raw key `validation.required` (no `en`/`ar` lang files exist yet, so the translator falls back to the key itself).

- [ ] **Step 3: Create `lang/en/validation.php`**

```php
<?php

return [

    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => 'The :attribute field must be an array.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    'attributes' => [],

];
```

- [ ] **Step 4: Create `lang/ar/validation.php`**

```php
<?php

return [

    'accepted' => 'يجب قبول حقل :attribute.',
    'accepted_if' => 'يجب قبول حقل :attribute عندما تكون قيمة :other هي :value.',
    'active_url' => 'يجب أن يكون حقل :attribute رابطاً صحيحاً.',
    'after' => 'يجب أن يكون حقل :attribute تاريخاً بعد :date.',
    'after_or_equal' => 'يجب أن يكون حقل :attribute تاريخاً بعد أو يساوي :date.',
    'alpha' => 'يجب أن يحتوي حقل :attribute على حروف فقط.',
    'alpha_dash' => 'يجب أن يحتوي حقل :attribute على حروف وأرقام وشرطات وشرطات سفلية فقط.',
    'alpha_num' => 'يجب أن يحتوي حقل :attribute على حروف وأرقام فقط.',
    'any_of' => 'حقل :attribute غير صالح.',
    'array' => 'يجب أن يكون حقل :attribute مصفوفة.',
    'ascii' => 'يجب أن يحتوي حقل :attribute على أحرف ورموز أحادية البايت فقط.',
    'before' => 'يجب أن يكون حقل :attribute تاريخاً قبل :date.',
    'before_or_equal' => 'يجب أن يكون حقل :attribute تاريخاً قبل أو يساوي :date.',
    'between' => [
        'array' => 'يجب أن يحتوي حقل :attribute على عدد عناصر بين :min و :max.',
        'file' => 'يجب أن يكون حجم حقل :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute بين :min و :max.',
        'string' => 'يجب أن يكون طول حقل :attribute بين :min و :max حرفاً.',
    ],
    'boolean' => 'يجب أن تكون قيمة حقل :attribute صحيحة أو خاطئة.',
    'can' => 'يحتوي حقل :attribute على قيمة غير مصرَّح بها.',
    'confirmed' => 'تأكيد حقل :attribute غير مطابق.',
    'contains' => 'يفتقد حقل :attribute إلى قيمة مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => 'يجب أن يكون حقل :attribute تاريخاً صحيحاً.',
    'date_equals' => 'يجب أن يكون حقل :attribute تاريخاً يساوي :date.',
    'date_format' => 'يجب أن يطابق حقل :attribute الصيغة :format.',
    'decimal' => 'يجب أن يحتوي حقل :attribute على :decimal منازل عشرية.',
    'declined' => 'يجب رفض حقل :attribute.',
    'declined_if' => 'يجب رفض حقل :attribute عندما تكون قيمة :other هي :value.',
    'different' => 'يجب أن يكون حقل :attribute و :other مختلفين.',
    'digits' => 'يجب أن يكون حقل :attribute مكوناً من :digits أرقام.',
    'digits_between' => 'يجب أن يكون حقل :attribute بين :min و :max أرقام.',
    'dimensions' => 'أبعاد صورة حقل :attribute غير صحيحة.',
    'distinct' => 'يحتوي حقل :attribute على قيمة مكررة.',
    'doesnt_contain' => 'يجب ألا يحتوي حقل :attribute على أي من القيم التالية: :values.',
    'doesnt_end_with' => 'يجب ألا ينتهي حقل :attribute بأي من القيم التالية: :values.',
    'doesnt_start_with' => 'يجب ألا يبدأ حقل :attribute بأي من القيم التالية: :values.',
    'email' => 'يجب أن يكون حقل :attribute بريداً إلكترونياً صحيحاً.',
    'encoding' => 'يجب أن يكون حقل :attribute مرمَّزاً بـ :encoding.',
    'ends_with' => 'يجب أن ينتهي حقل :attribute بأحد القيم التالية: :values.',
    'enum' => 'قيمة :attribute المختارة غير صحيحة.',
    'exists' => 'قيمة :attribute المختارة غير صحيحة.',
    'extensions' => 'يجب أن يكون حقل :attribute ملفاً بأحد الامتدادات التالية: :values.',
    'file' => 'يجب أن يكون حقل :attribute ملفاً.',
    'filled' => 'يجب أن يحتوي حقل :attribute على قيمة.',
    'gt' => [
        'array' => 'يجب أن يحتوي حقل :attribute على أكثر من :value عناصر.',
        'file' => 'يجب أن يكون حجم حقل :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول حقل :attribute أكبر من :value حرفاً.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي حقل :attribute على :value عناصر أو أكثر.',
        'file' => 'يجب أن يكون حجم حقل :attribute أكبر من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أكبر من أو تساوي :value.',
        'string' => 'يجب أن يكون طول حقل :attribute أكبر من أو يساوي :value حرفاً.',
    ],
    'hex_color' => 'يجب أن يكون حقل :attribute لوناً بصيغة هكساديسيمال صحيحة.',
    'image' => 'يجب أن يكون حقل :attribute صورة.',
    'in' => 'قيمة :attribute المختارة غير صحيحة.',
    'in_array' => 'يجب أن يكون حقل :attribute موجوداً ضمن :other.',
    'in_array_keys' => 'يجب أن يحتوي حقل :attribute على واحد على الأقل من المفاتيح التالية: :values.',
    'integer' => 'يجب أن يكون حقل :attribute رقماً صحيحاً.',
    'ip' => 'يجب أن يكون حقل :attribute عنوان IP صحيحاً.',
    'ipv4' => 'يجب أن يكون حقل :attribute عنوان IPv4 صحيحاً.',
    'ipv6' => 'يجب أن يكون حقل :attribute عنوان IPv6 صحيحاً.',
    'json' => 'يجب أن يكون حقل :attribute نص JSON صحيحاً.',
    'list' => 'يجب أن يكون حقل :attribute قائمة.',
    'lowercase' => 'يجب أن يكون حقل :attribute بحروف صغيرة.',
    'lt' => [
        'array' => 'يجب أن يحتوي حقل :attribute على أقل من :value عناصر.',
        'file' => 'يجب أن يكون حجم حقل :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أقل من :value.',
        'string' => 'يجب أن يكون طول حقل :attribute أقل من :value حرفاً.',
    ],
    'lte' => [
        'array' => 'يجب ألا يحتوي حقل :attribute على أكثر من :value عناصر.',
        'file' => 'يجب أن يكون حجم حقل :attribute أقل من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute أقل من أو تساوي :value.',
        'string' => 'يجب أن يكون طول حقل :attribute أقل من أو يساوي :value حرفاً.',
    ],
    'mac_address' => 'يجب أن يكون حقل :attribute عنوان MAC صحيحاً.',
    'max' => [
        'array' => 'يجب ألا يحتوي حقل :attribute على أكثر من :max عناصر.',
        'file' => 'يجب ألا يكون حجم حقل :attribute أكبر من :max كيلوبايت.',
        'numeric' => 'يجب ألا تكون قيمة حقل :attribute أكبر من :max.',
        'string' => 'يجب ألا يكون طول حقل :attribute أكبر من :max حرفاً.',
    ],
    'max_digits' => 'يجب ألا يحتوي حقل :attribute على أكثر من :max أرقام.',
    'mimes' => 'يجب أن يكون حقل :attribute ملفاً من النوع: :values.',
    'mimetypes' => 'يجب أن يكون حقل :attribute ملفاً من النوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي حقل :attribute على :min عناصر على الأقل.',
        'file' => 'يجب أن يكون حجم حقل :attribute :min كيلوبايت على الأقل.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute :min على الأقل.',
        'string' => 'يجب أن يكون طول حقل :attribute :min حرفاً على الأقل.',
    ],
    'min_digits' => 'يجب أن يحتوي حقل :attribute على :min أرقام على الأقل.',
    'missing' => 'يجب أن يكون حقل :attribute غير موجود.',
    'missing_if' => 'يجب أن يكون حقل :attribute غير موجود عندما تكون قيمة :other هي :value.',
    'missing_unless' => 'يجب أن يكون حقل :attribute غير موجود إلا إذا كانت قيمة :other هي :value.',
    'missing_with' => 'يجب أن يكون حقل :attribute غير موجود عند وجود :values.',
    'missing_with_all' => 'يجب أن يكون حقل :attribute غير موجود عند وجود :values.',
    'multiple_of' => 'يجب أن تكون قيمة حقل :attribute من مضاعفات :value.',
    'not_in' => 'قيمة :attribute المختارة غير صحيحة.',
    'not_regex' => 'صيغة حقل :attribute غير صحيحة.',
    'numeric' => 'يجب أن تكون قيمة حقل :attribute رقماً.',
    'password' => [
        'letters' => 'يجب أن يحتوي حقل :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي حقل :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي حقل :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي حقل :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت قيمة :attribute هذه ضمن تسريب بيانات سابق. الرجاء اختيار :attribute مختلف.',
    ],
    'present' => 'يجب أن يكون حقل :attribute موجوداً.',
    'present_if' => 'يجب أن يكون حقل :attribute موجوداً عندما تكون قيمة :other هي :value.',
    'present_unless' => 'يجب أن يكون حقل :attribute موجوداً إلا إذا كانت قيمة :other هي :value.',
    'present_with' => 'يجب أن يكون حقل :attribute موجوداً عند وجود :values.',
    'present_with_all' => 'يجب أن يكون حقل :attribute موجوداً عند وجود :values.',
    'prohibited' => 'حقل :attribute ممنوع.',
    'prohibited_if' => 'حقل :attribute ممنوع عندما تكون قيمة :other هي :value.',
    'prohibited_if_accepted' => 'حقل :attribute ممنوع عندما تكون قيمة :other مقبولة.',
    'prohibited_if_declined' => 'حقل :attribute ممنوع عندما تكون قيمة :other مرفوضة.',
    'prohibited_unless' => 'حقل :attribute ممنوع إلا إذا كانت قيمة :other ضمن :values.',
    'prohibits' => 'يمنع حقل :attribute وجود :other.',
    'regex' => 'صيغة حقل :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي حقل :attribute على مدخلات للمفاتيح: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما تكون قيمة :other هي :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عندما تكون قيمة :other مقبولة.',
    'required_if_declined' => 'حقل :attribute مطلوب عندما تكون قيمة :other مرفوضة.',
    'required_unless' => 'حقل :attribute مطلوب إلا إذا كانت قيمة :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند عدم وجود أي من :values.',
    'same' => 'يجب أن يطابق حقل :attribute حقل :other.',
    'size' => [
        'array' => 'يجب أن يحتوي حقل :attribute على :size عناصر.',
        'file' => 'يجب أن يكون حجم حقل :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة حقل :attribute :size.',
        'string' => 'يجب أن يكون طول حقل :attribute :size حرفاً.',
    ],
    'starts_with' => 'يجب أن يبدأ حقل :attribute بأحد القيم التالية: :values.',
    'string' => 'يجب أن يكون حقل :attribute نصاً.',
    'timezone' => 'يجب أن يكون حقل :attribute منطقة زمنية صحيحة.',
    'unique' => 'قيمة حقل :attribute مُستخدَمة من قبل.',
    'uploaded' => 'فشل رفع حقل :attribute.',
    'uppercase' => 'يجب أن يكون حقل :attribute بحروف كبيرة.',
    'url' => 'يجب أن يكون حقل :attribute رابطاً صحيحاً.',
    'ulid' => 'يجب أن يكون حقل :attribute رمز ULID صحيحاً.',
    'uuid' => 'يجب أن يكون حقل :attribute رمز UUID صحيحاً.',

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    'attributes' => [],

];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/Lang/ValidationTranslationTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: all pre-existing tests still pass, plus these 3 new ones. Record the total.

- [ ] **Step 7: Commit**

```bash
git add lang/en/validation.php lang/ar/validation.php tests/Unit/Lang/ValidationTranslationTest.php
git commit -m "feat: add English and Arabic validation.php lang files"
```

---

## Task 2: `api.php` translation files

**Files:**
- Create: `lang/en/api.php`
- Create: `lang/ar/api.php`
- Test: `tests/Unit/Lang/ApiTranslationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the `api.*` keys every later task's `__('api.<key>')` call resolves against. Full key list: `auth.otp_request_throttled`, `auth.otp_sent`, `auth.registration_code_invalid`, `auth.account_already_exists`, `auth.phone_already_registered`, `auth.code_purpose_mismatch_reset`, `auth.code_purpose_mismatch_registration`, `auth.code_invalid`, `auth.account_inactive`, `auth.invalid_credentials`, `auth.refresh_token_invalid`, `auth.logged_out`, `auth.password_reset_code_sent`, `auth.password_updated`, `auth.too_many_attempts`, `auth.unauthenticated`, `auth.forbidden`, `wallet.insufficient_balance`, `system.not_found`, `system.server_error`, `validation.failed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Lang;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class ApiTranslationTest extends TestCase
{
    public function test_every_api_key_resolves_in_english(): void
    {
        App::setLocale('en');

        $this->assertSame('These credentials do not match our records.', __('api.auth.invalid_credentials'));
        $this->assertSame('This account has been suspended. Please contact ADD.', __('api.auth.account_inactive'));
        $this->assertSame('Too many attempts. Please wait before trying again.', __('api.auth.too_many_attempts'));
        $this->assertSame('Unauthenticated.', __('api.auth.unauthenticated'));
        $this->assertSame('This action is unauthorized.', __('api.auth.forbidden'));
        $this->assertSame('Insufficient general balance to allocate this amount.', __('api.wallet.insufficient_balance'));
        $this->assertSame('The requested resource was not found.', __('api.system.not_found'));
        $this->assertSame('An unexpected error occurred. Please try again later.', __('api.system.server_error'));
        $this->assertSame('The given data is invalid.', __('api.validation.failed'));
    }

    public function test_every_api_key_resolves_in_arabic(): void
    {
        App::setLocale('ar');

        $this->assertSame('بيانات الدخول غير مطابقة لسجلاتنا.', __('api.auth.invalid_credentials'));
        $this->assertSame('هذا الحساب معلّق. الرجاء التواصل مع ADD.', __('api.auth.account_inactive'));
        $this->assertSame('محاولات كثيرة جداً. الرجاء الانتظار قبل المحاولة مرة أخرى.', __('api.auth.too_many_attempts'));
        $this->assertSame('غير مصادَق.', __('api.auth.unauthenticated'));
        $this->assertSame('غير مخوّل بتنفيذ هذا الإجراء.', __('api.auth.forbidden'));
        $this->assertSame('الرصيد العام غير كافٍ لتخصيص هذا المبلغ.', __('api.wallet.insufficient_balance'));
        $this->assertSame('المورد المطلوب غير موجود.', __('api.system.not_found'));
        $this->assertSame('حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.', __('api.system.server_error'));
        $this->assertSame('البيانات المُرسلة غير صالحة.', __('api.validation.failed'));
    }

    public function test_every_auth_flow_key_resolves_in_both_locales(): void
    {
        $keys = [
            'auth.otp_request_throttled',
            'auth.otp_sent',
            'auth.registration_code_invalid',
            'auth.account_already_exists',
            'auth.phone_already_registered',
            'auth.code_purpose_mismatch_reset',
            'auth.code_purpose_mismatch_registration',
            'auth.code_invalid',
            'auth.refresh_token_invalid',
            'auth.logged_out',
            'auth.password_reset_code_sent',
            'auth.password_updated',
        ];

        foreach (['en', 'ar'] as $locale) {
            App::setLocale($locale);

            foreach ($keys as $key) {
                $this->assertNotSame("api.{$key}", __("api.{$key}"), "Missing {$locale} translation for {$key}");
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Lang/ApiTranslationTest.php`
Expected: FAIL — `__('api.auth.invalid_credentials')` returns the raw key (no `lang/{en,ar}/api.php` exists yet).

- [ ] **Step 3: Create `lang/en/api.php`**

```php
<?php

return [

    'auth' => [
        'otp_request_throttled' => 'Too many requests. Please wait before requesting a new code.',
        'otp_sent' => 'Verification code sent.',
        'registration_code_invalid' => 'Invalid or expired code. Please start signing up again.',
        'account_already_exists' => 'An account already exists for this number or email. Please log in instead.',
        'phone_already_registered' => 'This number already has an account. Please log in instead.',
        'code_purpose_mismatch_reset' => 'That code was issued to reset a password, not to create an account.',
        'code_purpose_mismatch_registration' => 'That code was issued to create an account, not to reset a password.',
        'code_invalid' => 'Invalid or expired code.',
        'account_inactive' => 'This account has been suspended. Please contact ADD.',
        'invalid_credentials' => 'These credentials do not match our records.',
        'refresh_token_invalid' => 'Invalid or expired refresh token.',
        'logged_out' => 'Logged out.',
        'password_reset_code_sent' => 'If that number has an account, a reset code has been sent to it.',
        'password_updated' => 'Password updated. Please log in with your new password.',
        'too_many_attempts' => 'Too many attempts. Please wait before trying again.',
        'unauthenticated' => 'Unauthenticated.',
        'forbidden' => 'This action is unauthorized.',
    ],

    'wallet' => [
        'insufficient_balance' => 'Insufficient general balance to allocate this amount.',
    ],

    'system' => [
        'not_found' => 'The requested resource was not found.',
        'server_error' => 'An unexpected error occurred. Please try again later.',
    ],

    'validation' => [
        'failed' => 'The given data is invalid.',
    ],

];
```

- [ ] **Step 4: Create `lang/ar/api.php`**

```php
<?php

return [

    'auth' => [
        'otp_request_throttled' => 'طلبات كثيرة. الرجاء الانتظار قبل طلب رمز جديد.',
        'otp_sent' => 'تم إرسال رمز التحقق.',
        'registration_code_invalid' => 'الرمز غير صحيح أو منتهي الصلاحية. الرجاء إعادة التسجيل.',
        'account_already_exists' => 'يوجد حساب مسجّل بهذا الرقم أو البريد الإلكتروني مسبقاً. الرجاء تسجيل الدخول.',
        'phone_already_registered' => 'هذا الرقم مسجّل بحساب مسبقاً. الرجاء تسجيل الدخول.',
        'code_purpose_mismatch_reset' => 'هذا الرمز مخصّص لإعادة تعيين كلمة المرور، لا لإنشاء حساب.',
        'code_purpose_mismatch_registration' => 'هذا الرمز مخصّص لإنشاء حساب، لا لإعادة تعيين كلمة المرور.',
        'code_invalid' => 'الرمز غير صحيح أو منتهي الصلاحية.',
        'account_inactive' => 'هذا الحساب معلّق. الرجاء التواصل مع ADD.',
        'invalid_credentials' => 'بيانات الدخول غير مطابقة لسجلاتنا.',
        'refresh_token_invalid' => 'رمز التحديث غير صحيح أو منتهي الصلاحية.',
        'logged_out' => 'تم تسجيل الخروج.',
        'password_reset_code_sent' => 'إذا كان هذا الرقم مرتبطاً بحساب، فسيتم إرسال رمز إعادة التعيين إليه.',
        'password_updated' => 'تم تحديث كلمة المرور. الرجاء تسجيل الدخول بكلمة المرور الجديدة.',
        'too_many_attempts' => 'محاولات كثيرة جداً. الرجاء الانتظار قبل المحاولة مرة أخرى.',
        'unauthenticated' => 'غير مصادَق.',
        'forbidden' => 'غير مخوّل بتنفيذ هذا الإجراء.',
    ],

    'wallet' => [
        'insufficient_balance' => 'الرصيد العام غير كافٍ لتخصيص هذا المبلغ.',
    ],

    'system' => [
        'not_found' => 'المورد المطلوب غير موجود.',
        'server_error' => 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',
    ],

    'validation' => [
        'failed' => 'البيانات المُرسلة غير صالحة.',
    ],

];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Unit/Lang/ApiTranslationTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: all pre-existing tests plus the 3 (Task 1) + 3 (Task 2) new ones pass.

- [ ] **Step 7: Commit**

```bash
git add lang/en/api.php lang/ar/api.php tests/Unit/Lang/ApiTranslationTest.php
git commit -m "feat: add api.php translation keys for auth, wallet, and system messages"
```

---

## Task 3: Locale resolution — middleware + listener

**Files:**
- Create: `app/Http/Middleware/SetLocaleFromHeader.php`
- Create: `app/Listeners/SetLocaleFromUserPreference.php`
- Modify: `bootstrap/app.php` (register the middleware)
- Modify: `app/Providers/AppServiceProvider.php` (register the listener)
- Test: `tests/Unit/Http/Middleware/SetLocaleFromHeaderTest.php`
- Test: `tests/Feature/Identity/LocaleResolutionTest.php`

**Interfaces:**
- Consumes: `lang/{en,ar}/api.php` from Task 2 is not required for this task's own tests (they check `App::getLocale()`, not message text), but is what makes the mechanism useful to later tasks.
- Produces: after this task, `App::getLocale()` is `'ar'`/`'en'` per the header on every request under `routes/api.php`, corrected to the authenticated user's `preferred_language` once auth resolves (if no valid header was sent). `SetLocaleFromHeader::SUPPORTED_LOCALES` (`['ar', 'en']`) is the shared constant the listener also reads.

- [ ] **Step 1: Write the failing middleware unit test**

```php
<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class SetLocaleFromHeaderTest extends TestCase
{
    private function run(Request $request): void
    {
        (new SetLocaleFromHeader)->handle($request, fn ($req) => response()->json([]));
    }

    public function test_a_valid_ar_header_sets_the_locale(): void
    {
        $this->run(Request::create('/', 'GET', server: ['HTTP_LANG' => 'ar']));

        $this->assertSame('ar', App::getLocale());
    }

    public function test_a_valid_en_header_sets_the_locale(): void
    {
        $this->run(Request::create('/', 'GET', server: ['HTTP_LANG' => 'en']));

        $this->assertSame('en', App::getLocale());
    }

    public function test_the_header_is_case_insensitive(): void
    {
        $this->run(Request::create('/', 'GET', server: ['HTTP_LANG' => 'EN']));

        $this->assertSame('en', App::getLocale());
    }

    public function test_a_missing_header_falls_back_to_arabic(): void
    {
        $this->run(Request::create('/', 'GET'));

        $this->assertSame('ar', App::getLocale());
    }

    public function test_an_unsupported_header_value_falls_back_to_arabic(): void
    {
        $this->run(Request::create('/', 'GET', server: ['HTTP_LANG' => 'fr']));

        $this->assertSame('ar', App::getLocale());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Http/Middleware/SetLocaleFromHeaderTest.php`
Expected: FAIL — class `App\Http\Middleware\SetLocaleFromHeader` does not exist.

- [ ] **Step 3: Create `app/Http/Middleware/SetLocaleFromHeader.php`**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header only. Deliberately does not consult the authenticated user here —
 * this middleware is prepended to the `api` middleware group, which runs
 * before `auth:sanctum`'s own route middleware resolves $request->user()
 * (see EnsureAuthenticatedUserIsActive's docblock for the same ordering
 * trap, confirmed empirically there). SetLocaleFromUserPreference corrects
 * this provisional value once a guard actually resolves a user.
 */
class SetLocaleFromHeader
{
    public const SUPPORTED_LOCALES = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $header = strtolower((string) $request->header('lang'));

        App::setLocale(in_array($header, self::SUPPORTED_LOCALES, true) ? $header : 'ar');

        return $next($request);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Http/Middleware/SetLocaleFromHeaderTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Register the middleware in `bootstrap/app.php`**

Add the import alongside the existing Sanctum/Spatie imports:

```php
use App\Http\Middleware\SetLocaleFromHeader;
```

Add this line as the first statement inside the existing `->withMiddleware(function (Middleware $middleware): void { ... })` closure, before `$middleware->statefulApi();`:

```php
        // Every response speaks whichever locale this resolves — prepended so
        // it runs before anything else in the pipeline, including auth:sanctum.
        $middleware->api(prepend: SetLocaleFromHeader::class);

        $middleware->statefulApi();
```

- [ ] **Step 6: Write the failing feature test for the fallback-to-preference behavior**

```php
<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;

class LocaleResolutionTest extends IdentityTestCase
{
    private function member(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'phone' => '0912345678',
            'password' => 'correct-horse',
        ], $overrides));

        $user->assignRole('member');

        return $user;
    }

    public function test_a_valid_header_is_read_on_an_unauthenticated_request(): void
    {
        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/login', [
            'phone' => '0000000000',
            'password' => 'anything',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'بيانات الدخول غير مطابقة لسجلاتنا.');
    }

    public function test_no_header_falls_back_to_the_authenticated_users_preferred_language(): void
    {
        $member = $this->member(['preferred_language' => 'en']);

        // A real token, not Sanctum::actingAs() — actingAs() sets the guard's
        // user directly and never dispatches TokenAuthenticated (confirmed by
        // this codebase's own SuspendedAccountAccessTest docblock), which is
        // exactly the event SetLocaleFromUserPreference listens for.
        $token = $member->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_a_valid_header_overrides_the_authenticated_users_preferred_language(): void
    {
        $member = $this->member(['preferred_language' => 'ar']);

        $token = $member->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('lang', 'en')
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $this->assertSame('en', app()->getLocale());
    }
}
```

Note: `api.auth.invalid_credentials` isn't wired into `MemberAuthController` until Task 5 — the first test above will fail on the message text (still the old English literal) until then. That's expected; this task's own pass/fail cycle is Steps 2/4 above for the middleware. Re-run this feature test as a **regression check** at the end of Task 5 once the literal is migrated (Task 5, Step 6 does this).

- [ ] **Step 7: Run test to verify the locale-fallback assertions fail for the right reason**

Run: `php artisan test tests/Feature/Identity/LocaleResolutionTest.php`
Expected: `test_a_valid_header_is_read_on_an_unauthenticated_request` FAILS (message is still the English literal — Task 5 hasn't migrated it yet). `test_no_header_falls_back_to_the_authenticated_users_preferred_language` and `test_a_valid_header_overrides_the_authenticated_users_preferred_language` also FAIL, on `assertSame('en', app()->getLocale())` — nothing corrects `SetLocaleFromHeader`'s provisional `'ar'` yet, since the listener created in the next step doesn't exist.

- [ ] **Step 8: Create `app/Listeners/SetLocaleFromUserPreference.php`**

```php
<?php

namespace App\Listeners;

use App\Domain\Identity\Models\User;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\App;
use Laravel\Sanctum\Events\TokenAuthenticated;

/**
 * Corrects the provisional locale SetLocaleFromHeader set before
 * authentication resolved. Listens to the same two events
 * EnsureAuthenticatedUserIsActive does, for the same reason: this is the
 * first point after the middleware pipeline where $request->user() (or an
 * equivalent) is reliably available.
 */
class SetLocaleFromUserPreference
{
    public function handle(TokenAuthenticated|Authenticated $event): void
    {
        $header = strtolower((string) request()->header('lang'));

        if (in_array($header, SetLocaleFromHeader::SUPPORTED_LOCALES, true)) {
            return;
        }

        $user = $event instanceof TokenAuthenticated
            ? $event->token->tokenable
            : $event->user;

        if ($user instanceof User) {
            App::setLocale($user->preferred_language);
        }
    }
}
```

- [ ] **Step 9: Register the listener in `AppServiceProvider::boot()`**

Add imports:

```php
use App\Listeners\SetLocaleFromUserPreference;
```

Add these two lines directly after the existing `EnsureAuthenticatedUserIsActive` listener registrations in `boot()`:

```php
        Event::listen(TokenAuthenticated::class, EnsureAuthenticatedUserIsActive::class);
        Event::listen(Authenticated::class, EnsureAuthenticatedUserIsActive::class);

        // Runs after the above: corrects the provisional locale
        // SetLocaleFromHeader set before auth resolved, to the user's
        // preferred_language — but only when no valid `lang` header was sent.
        Event::listen(TokenAuthenticated::class, SetLocaleFromUserPreference::class);
        Event::listen(Authenticated::class, SetLocaleFromUserPreference::class);
```

- [ ] **Step 10: Run test to verify the locale-fallback assertions pass**

Run: `php artisan test tests/Feature/Identity/LocaleResolutionTest.php`
Expected: `test_no_header_falls_back_to_the_authenticated_users_preferred_language` and `test_a_valid_header_overrides_the_authenticated_users_preferred_language` PASS. `test_a_valid_header_is_read_on_an_unauthenticated_request` still FAILS (expected — fixed in Task 5).

- [ ] **Step 11: Run the full suite**

Run: `composer test`
Expected: everything from Tasks 1–2 still passes; the 5 middleware unit tests pass; 2 of the 3 feature tests in `LocaleResolutionTest` pass (the third is a known, tracked failure until Task 5). Record the total and the one expected failure.

- [ ] **Step 12: Commit**

```bash
git add app/Http/Middleware/SetLocaleFromHeader.php app/Listeners/SetLocaleFromUserPreference.php bootstrap/app.php app/Providers/AppServiceProvider.php tests/Unit/Http/Middleware/SetLocaleFromHeaderTest.php tests/Feature/Identity/LocaleResolutionTest.php
git commit -m "feat: resolve request locale from lang header and preferred_language"
```

---

## Task 4: Exception handler translations

**Files:**
- Modify: `bootstrap/app.php` (`withExceptions` closure)
- Test: `tests/Feature/ErrorResponseLocalizationTest.php`

**Interfaces:**
- Consumes: `lang/{en,ar}/api.php` (Task 2) for `validation.failed`, `auth.too_many_attempts`, `auth.unauthenticated`, `auth.forbidden`, `system.not_found`, `system.server_error`; `lang/{en,ar}/validation.php` (Task 1) for the `errors` bag; `SetLocaleFromHeader`/`SetLocaleFromUserPreference` (Task 3) to resolve the locale these messages render in.
- Produces: every error response under `/api/v1` — validation, throttling, unauthenticated, forbidden, not-found, and uncaught 500s (when `APP_DEBUG` is off) — carries a translated `message`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The generic exception-handler branches in bootstrap/app.php need the same
 * translated-by-locale treatment as the messages controllers build by hand.
 */
class ErrorResponseLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_validation_errors_return_a_translated_top_level_message(): void
    {
        $en = $this->withHeader('lang', 'en')->postJson('/api/v1/auth/register', []);
        $ar = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/register', []);

        $en->assertStatus(422);
        $en->assertJsonPath('message', 'The given data is invalid.');
        $en->assertJsonStructure(['message', 'errors']);

        $ar->assertStatus(422);
        $ar->assertJsonPath('message', 'البيانات المُرسلة غير صالحة.');
    }

    public function test_route_level_throttling_returns_a_translated_message(): void
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'bogus'])->assertStatus(401);
        }

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/auth/refresh', ['refresh_token' => 'bogus']);

        $response->assertStatus(429);
        $response->assertJsonPath('message', 'Too many attempts. Please wait before trying again.');
    }

    public function test_an_unauthenticated_request_to_a_protected_route_returns_a_translated_message(): void
    {
        $en = $this->withHeader('lang', 'en')->getJson('/api/v1/auth/me');
        $ar = $this->withHeader('lang', 'ar')->getJson('/api/v1/auth/me');

        $en->assertStatus(401);
        $en->assertJsonPath('message', 'Unauthenticated.');

        $ar->assertStatus(401);
        $ar->assertJsonPath('message', 'غير مصادَق.');
    }

    public function test_a_policy_denial_returns_a_translated_message(): void
    {
        $company = Company::factory()->create();
        $wallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        (new WalletService)->creditGeneral($wallet, '100.00', WalletTransactionSource::TopUp);

        $member = User::factory()->create();
        $member->assignRole('member');
        $company->members()->attach($member->id, ['is_admin' => false]);

        Sanctum::actingAs($member, ['*']);

        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'This action is unauthorized.');
    }

    public function test_an_unknown_route_returns_a_translated_not_found_message(): void
    {
        $en = $this->withHeader('lang', 'en')->getJson('/api/v1/this-route-does-not-exist');
        $ar = $this->withHeader('lang', 'ar')->getJson('/api/v1/this-route-does-not-exist');

        $en->assertStatus(404);
        $en->assertJsonPath('message', 'The requested resource was not found.');

        $ar->assertStatus(404);
        $ar->assertJsonPath('message', 'المورد المطلوب غير موجود.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/ErrorResponseLocalizationTest.php`
Expected: FAIL on all 5 — validation top-level message is still Laravel's default `"The given data was invalid."`; throttle/401/403/404 all still return Laravel's untranslated defaults (`"Too Many Attempts."`, `"Unauthenticated."` happens to already say that in English but the Arabic case fails, `"This action is unauthorized."` likewise, `"Not Found."` fails for both locales).

- [ ] **Step 3: Replace the import block in `bootstrap/app.php`**

By this point (after Task 3), the top of the file already has `use App\Http\Middleware\SetLocaleFromHeader;` — do not add a second copy of it, PHP treats a repeated `use` of the same class as a fatal error. Replace the whole import block with this full list (six new imports added, everything else unchanged):

```php
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;
```

- [ ] **Step 4: Add the six `render()` calls inside `withExceptions`**

Replace:

```php
    ->withExceptions(function (Exceptions $exceptions): void {
        // This app has no server-rendered views — every response, including
        // error responses, must be JSON. Without this, an unauthenticated
        // request that doesn't send "Accept: application/json" (most plain
        // fetch/axios calls) crashes with a 500 instead of a 401, because
        // Laravel's default guest-redirect falls back to route('login'),
        // which doesn't exist (Fortify's view routes are disabled).
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
```

with:

```php
    ->withExceptions(function (Exceptions $exceptions): void {
        // This app has no server-rendered views — every response, including
        // error responses, must be JSON. Without this, an unauthenticated
        // request that doesn't send "Accept: application/json" (most plain
        // fetch/axios calls) crashes with a 500 instead of a 401, because
        // Laravel's default guest-redirect falls back to route('login'),
        // which doesn't exist (Fortify's view routes are disabled).
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Six specific exception shapes get a translated `message` instead
        // of Laravel's English-only default. Anything else (e.g. an
        // abort(403, __('api.auth.account_inactive')) elsewhere in the app)
        // already carries its own translated message and falls through to
        // Laravel's default HttpExceptionInterface rendering unchanged.
        $exceptions->render(fn (ValidationException $e) => response()->json([
            'message' => __('api.validation.failed'),
            'errors' => $e->errors(),
        ], $e->status));

        $exceptions->render(fn (ThrottleRequestsException $e) => response()->json([
            'message' => __('api.auth.too_many_attempts'),
        ], 429, $e->getHeaders()));

        $exceptions->render(fn (AuthenticationException $e) => response()->json([
            'message' => __('api.auth.unauthenticated'),
        ], 401));

        $exceptions->render(fn (AuthorizationException $e) => response()->json([
            'message' => __('api.auth.forbidden'),
        ], 403));

        $exceptions->render(fn (NotFoundHttpException $e) => response()->json([
            'message' => __('api.system.not_found'),
        ], 404));

        // Registered last: Throwable matches everything, so an earlier match
        // above always wins. Returning null in debug mode defers to Laravel's
        // own rich diagnostic rendering — a local-dev tool this doesn't touch.
        $exceptions->render(function (Throwable $e) {
            if (config('app.debug')) {
                return null;
            }

            return response()->json(['message' => __('api.system.server_error')], 500);
        });
    })->create();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/ErrorResponseLocalizationTest.php`
Expected: PASS (5 tests)

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: all prior tests pass; the one known failure from Task 3 (`test_a_valid_header_is_read_on_an_unauthenticated_request`) still fails for the same tracked reason. Record the total.

- [ ] **Step 7: Commit**

```bash
git add bootstrap/app.php tests/Feature/ErrorResponseLocalizationTest.php
git commit -m "feat: translate validation, throttle, and generic HTTP error responses"
```

Scope note: the generic 500 branch (`api.system.server_error`) has no dedicated automated test — triggering a genuine uncaught exception cleanly through the real HTTP stack needs either a throwaway route or a deliberately-broken code path, and it isn't one of the six scenarios in the original request's testing checklist. The branch itself is a one-line, low-risk change; flagging the gap rather than adding a fragile test for it.

---

## Task 5: Migrate `MemberAuthController` and `RegisterRequest`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Auth/MemberAuthController.php`
- Modify: `app/Http/Requests/Auth/RegisterRequest.php`
- Modify: `tests/Feature/Identity/LocaleResolutionTest.php` (regression check from Task 3)

**Interfaces:**
- Consumes: `api.auth.*` keys from Task 2.
- Produces: no new interface — this is a pure literal-to-`__()` swap; every existing caller of these two files is unaffected in shape, only in the text of `message`/`errors.phone.0`.

- [ ] **Step 1: Confirm the Task 3 regression test currently fails as expected**

Run: `php artisan test tests/Feature/Identity/LocaleResolutionTest.php --filter=test_a_valid_header_is_read_on_an_unauthenticated_request`
Expected: FAIL — `message` is still `'These credentials do not match our records.'` regardless of the `ar` header.

- [ ] **Step 2: Replace the literals in `MemberAuthController.php`**

```php
        } catch (OtpThrottledException $e) {
            return response()->json([
                'message' => __('api.auth.otp_request_throttled'),
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        }

        return response()->json(['message' => __('api.auth.otp_sent')]);
```

```php
        if (! $pending || ! $pending->isUsable()) {
            return response()->json([
                'message' => __('api.auth.registration_code_invalid'),
            ], 422);
        }
```

```php
        if ($this->phoneOrEmailIsTaken($pending)) {
            return response()->json([
                'message' => __('api.auth.account_already_exists'),
            ], 409);
        }
```

```php
        if ($result === OtpResult::PurposeMismatch) {
            return response()->json([
                'message' => __('api.auth.code_purpose_mismatch_reset'),
            ], 422);
        }

        if ($result !== OtpResult::Verified) {
            return response()->json(['message' => __('api.auth.code_invalid')], 422);
        }
```

```php
        if ($user->status !== 'active') {
            return response()->json([
                'message' => __('api.auth.account_inactive'),
                'status' => $user->status,
            ], 403);
        }
```

```php
    private function credentialsRejected(): JsonResponse
    {
        return response()->json(['message' => __('api.auth.invalid_credentials')], 401);
    }
```

```php
        } catch (InvalidRefreshTokenException) {
            return response()->json(['message' => __('api.auth.refresh_token_invalid')], 401);
        }
```

```php
        return response()->json(['message' => __('api.auth.logged_out')]);
```

- [ ] **Step 3: Replace the literal in `RegisterRequest.php`**

```php
    public function messages(): array
    {
        return [
            'phone.unique' => __('api.auth.phone_already_registered'),
        ];
    }
```

- [ ] **Step 4: Confirm no literal strings remain**

Run: `grep -n "'message' =>" app/Http/Controllers/Api/V1/Auth/MemberAuthController.php`
Expected: every match shows `__('api....')`, none show a quoted English/Arabic sentence.

- [ ] **Step 5: Run the regression test to verify it passes**

Run: `php artisan test tests/Feature/Identity/LocaleResolutionTest.php`
Expected: PASS (all 3 tests, including the one that was failing since Task 3).

- [ ] **Step 6: Run the existing MemberAuthController-adjacent tests**

Run: `php artisan test tests/Feature/Identity/MemberLoginTest.php`
Expected: PASS — this file only compares the two failure messages to each other (`assertSame($wrongPassword->json('message'), $unknownPhone->json('message'))`) and checks structure/status codes, never an exact literal, so it's unaffected by the wording change.

- [ ] **Step 7: Run the full suite**

Run: `composer test`
Expected: all tests pass, zero known failures remaining. Record the total.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Api/V1/Auth/MemberAuthController.php app/Http/Requests/Auth/RegisterRequest.php tests/Feature/Identity/LocaleResolutionTest.php
git commit -m "refactor: translate MemberAuthController and RegisterRequest messages"
```

---

## Task 6: Migrate `MemberPasswordController`

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Auth/MemberPasswordController.php`

**Interfaces:**
- Consumes: `api.auth.*` keys from Task 2 (`password_reset_code_sent`, `code_invalid`, `code_purpose_mismatch_registration`, `password_updated`).
- Produces: no new interface.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;

class PasswordResetLocalizationTest extends IdentityTestCase
{
    public function test_forgot_password_response_is_translated(): void
    {
        $member = User::factory()->create(['phone' => '0912345678']);
        $member->assignRole('member');

        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/password/forgot', [
            'phone' => '0912345678',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'إذا كان هذا الرقم مرتبطاً بحساب، فسيتم إرسال رمز إعادة التعيين إليه.');
    }

    public function test_reset_with_an_invalid_code_is_translated(): void
    {
        $member = User::factory()->create(['phone' => '0912345678']);
        $member->assignRole('member');

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/auth/password/reset', [
            'phone' => '0912345678',
            'code' => '000000',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Invalid or expired code.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Identity/PasswordResetLocalizationTest.php`
Expected: FAIL — both messages are still the old English literals regardless of the `lang` header (the `ar` test especially, since it asks for Arabic and gets English).

- [ ] **Step 3: Replace the literals in `MemberPasswordController.php`**

```php
        return response()->json([
            'message' => __('api.auth.password_reset_code_sent'),
        ]);
```

```php
        if (! $user) {
            return response()->json(['message' => __('api.auth.code_invalid')], 422);
        }
```

```php
        if ($result === OtpResult::PurposeMismatch) {
            return response()->json([
                'message' => __('api.auth.code_purpose_mismatch_registration'),
            ], 422);
        }

        if ($result !== OtpResult::Verified) {
            return response()->json(['message' => __('api.auth.code_invalid')], 422);
        }
```

```php
        return response()->json([
            'message' => __('api.auth.password_updated'),
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Identity/PasswordResetLocalizationTest.php`
Expected: PASS (2 tests)

- [ ] **Step 5: Run the existing PasswordResetTest.php to confirm no regression**

Run: `php artisan test tests/Feature/Identity/PasswordResetTest.php`
Expected: PASS — check the file first with a quick grep for any exact-literal assertions before running, same way Task 5 checked `MemberLoginTest`.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: all tests pass. Record the total.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Api/V1/Auth/MemberPasswordController.php tests/Feature/Identity/PasswordResetLocalizationTest.php
git commit -m "refactor: translate MemberPasswordController messages"
```

---

## Task 7: Migrate the rate limiter, the active-account guard, and the wallet allocation controller

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Listeners/EnsureAuthenticatedUserIsActive.php`
- Modify: `app/Http/Controllers/Api/V1/Member/CompanyWalletAllocationController.php`
- Modify: `tests/Feature/Membership/WalletTransactionAllowedUsersTest.php` (one existing assertion needs a `lang` header now that the response is locale-dependent)

**Interfaces:**
- Consumes: `api.auth.too_many_attempts`, `api.auth.account_inactive`, `api.wallet.insufficient_balance` from Task 2.
- Produces: no new interface.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;

class AccountInactiveLocalizationTest extends IdentityTestCase
{
    public function test_a_suspended_accounts_login_rejection_is_translated(): void
    {
        $member = User::factory()->create([
            'phone' => '0912345678',
            'password' => 'correct-horse',
        ]);
        $member->assignRole('member');
        $member->deactivate('testing');

        $response = $this->withHeader('lang', 'ar')->postJson('/api/v1/auth/login', [
            'phone' => '0912345678',
            'password' => 'correct-horse',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'هذا الحساب معلّق. الرجاء التواصل مع ADD.');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Identity/AccountInactiveLocalizationTest.php`
Expected: FAIL — message is still `'This account has been suspended. Please contact ADD.'` (login's own check, in `MemberAuthController::login()`, not yet migrated — this specific literal was in scope for Task 5's file but intentionally left for this task since it shares wording with `EnsureAuthenticatedUserIsActive`'s `abort()`; see Step 3 below, which migrates both together for the shared key).

- [ ] **Step 3: Replace the literal in `MemberAuthController::login()`**

```php
        if ($user->status !== 'active') {
            return response()->json([
                'message' => __('api.auth.account_inactive'),
                'status' => $user->status,
            ], 403);
        }
```

(This is the same edit already listed under Task 5, Step 2 — if Task 5 already applied it, skip; it's repeated here because this task's test specifically exercises it alongside the `abort()` call below, which shares the same key.)

- [ ] **Step 4: Replace the literal in `EnsureAuthenticatedUserIsActive.php`**

```php
        if ($user->status !== 'active' && ! request()->routeIs(...self::EXEMPT_ROUTES)) {
            abort(403, __('api.auth.account_inactive'));
        }
```

- [ ] **Step 5: Replace the literal in `AppServiceProvider::registerLoginRateLimiter()`**

```php
    private function registerLoginRateLimiter(): void
    {
        RateLimiter::for('member-login', fn (Request $request) => Limit::perMinute(5)
            ->by($request->input('phone').'|'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json([
                'message' => __('api.auth.too_many_attempts'),
                'retry_after' => (int) ($headers['Retry-After'] ?? 60),
            ], 429, $headers)));
    }
```

- [ ] **Step 6: Replace the literal in `CompanyWalletAllocationController.php`**

```php
        } catch (InsufficientBalanceException) {
            return response()->json(['message' => __('api.wallet.insufficient_balance')], 422);
        }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test tests/Feature/Identity/AccountInactiveLocalizationTest.php`
Expected: PASS

- [ ] **Step 8: Fix the one existing test asserting an exact literal that's now locale-dependent**

In `tests/Feature/Membership/WalletTransactionAllowedUsersTest.php`, `test_allocating_more_than_the_available_general_balance_fails_with_no_partial_state()`:

Replace:

```php
        $response = $this->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Insufficient general balance to allocate this amount.']);
```

with:

```php
        $response = $this->withHeader('lang', 'en')->postJson("/api/v1/member/companies/{$company->id}/wallet-allocations", [
            'category' => 'cafe',
            'amount' => '20.00',
            'user_ids' => [$member->id],
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => 'Insufficient general balance to allocate this amount.']);
```

Reason: `$admin` (created via plain `User::factory()->create()`) has no `preferred_language` override, so it defaults to `'ar'` at the DB level; without an explicit `lang: en` header, the now-translated message would come back in Arabic and this assertion would fail. The English wording itself is unchanged — this is the same string Task 2 put in `lang/en/api.php`.

- [ ] **Step 9: Run the fixed test**

Run: `php artisan test tests/Feature/Membership/WalletTransactionAllowedUsersTest.php`
Expected: PASS (all tests in the file, including the one just fixed).

- [ ] **Step 10: Confirm no literal strings remain anywhere in `app/`**

Run: `grep -rn "'message' =>" app --include="*.php"`
Expected: every remaining match is `__('api....')` — none are quoted sentences. (There may be zero matches if `grep` only finds the exact-quote pattern; a follow-up `grep -rn "response()->json(\['message'" app` is a useful cross-check for the same thing.)

- [ ] **Step 11: Run the full suite**

Run: `composer test`
Expected: all tests pass, zero known failures remaining. Record the total.

- [ ] **Step 12: Commit**

```bash
git add app/Providers/AppServiceProvider.php app/Listeners/EnsureAuthenticatedUserIsActive.php app/Http/Controllers/Api/V1/Member/CompanyWalletAllocationController.php tests/Feature/Identity/AccountInactiveLocalizationTest.php tests/Feature/Membership/WalletTransactionAllowedUsersTest.php
git commit -m "refactor: translate remaining hardcoded messages (rate limiter, active-account guard, wallet allocation)"
```

---

## Task 8: End-to-end locale scenario tests

**Files:**
- Create: `tests/Feature/Identity/ApiLocalizationTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–7 — this is the scenario-level test the original request's testing checklist (items a–f) describes, distinct from Tasks 3/4/5/6/7's per-unit regression tests.
- Produces: nothing further downstream — this is the plan's terminal test task.

- [ ] **Step 1: Write the test file**

```php
<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * The six scenarios the original localization request calls out by name:
 * (a) lang=ar on wrong credentials, (b) lang=en on the same, (c) no header
 * for an authenticated member with a stored preference, (d) header
 * overriding that stored preference, (e) an invalid header value falling
 * back rather than failing the request, (f) a translated 429.
 */
class ApiLocalizationTest extends IdentityTestCase
{
    private const PHONE = '0912345678';

    private const PASSWORD = 'correct-horse';

    private function member(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'phone' => self::PHONE,
            'password' => self::PASSWORD,
        ], $overrides));

        $user->assignRole('member');

        return $user;
    }

    private function loginWithWrongPassword(?string $lang = null): TestResponse
    {
        $request = $lang ? $this->withHeader('lang', $lang) : $this;

        return $request->postJson('/api/v1/auth/login', [
            'phone' => self::PHONE,
            'password' => 'not-the-password',
        ]);
    }

    public function test_lang_ar_header_returns_the_arabic_message_for_wrong_credentials(): void
    {
        $this->member();

        $response = $this->loginWithWrongPassword('ar');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'بيانات الدخول غير مطابقة لسجلاتنا.');
    }

    public function test_lang_en_header_returns_the_english_message_for_wrong_credentials(): void
    {
        $this->member();

        $response = $this->loginWithWrongPassword('en');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'These credentials do not match our records.');
    }

    public function test_no_header_falls_back_to_the_authenticated_members_stored_preference(): void
    {
        $member = $this->member(['preferred_language' => 'en']);

        // A real token, not Sanctum::actingAs() — actingAs() sets the guard's
        // user directly and never dispatches TokenAuthenticated, which is
        // exactly the event SetLocaleFromUserPreference (Task 3) listens for.
        // Using actingAs() here would make this scenario pass vacuously,
        // without the listener ever running.
        $token = $member->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/member/preferences/language', [
                'preferred_language' => 'fr',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The given data is invalid.');
    }

    public function test_header_overrides_the_stored_preference(): void
    {
        $member = $this->member(['preferred_language' => 'ar']);

        $token = $member->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('lang', 'en')
            ->patchJson('/api/v1/member/preferences/language', [
                'preferred_language' => 'fr',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'The given data is invalid.');
    }

    public function test_an_unsupported_header_value_falls_back_to_arabic_and_does_not_fail_the_request(): void
    {
        $this->member();

        $response = $this->loginWithWrongPassword('fr');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'بيانات الدخول غير مطابقة لسجلاتنا.');
    }

    public function test_the_login_throttle_response_is_translated(): void
    {
        $this->member();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->loginWithWrongPassword('en');
        }

        $response = $this->loginWithWrongPassword('en');

        $response->assertStatus(429);
        $response->assertJsonPath('message', 'Too many attempts. Please wait before trying again.');
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `php artisan test tests/Feature/Identity/ApiLocalizationTest.php`
Expected: PASS (6 tests) — every dependency (middleware, listener, translated controllers, translated exception handler) is already in place from Tasks 1–7, so this should be green on the first run. If any assertion fails, that means an earlier task's migration was incomplete — stop and fix the earlier task rather than adjusting this test's expectations.

- [ ] **Step 3: Run the full suite and confirm the final total**

Run: `composer test`
Expected: 100% pass, zero known failures. This is the number to report as the final total for the whole feature.

- [ ] **Step 4: Run Pint**

Run: `./vendor/bin/pint --test`
Expected: no violations. If there are any, run `./vendor/bin/pint` to fix, then re-run `composer test` once more to confirm nothing broke.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Identity/ApiLocalizationTest.php
git commit -m "test: add end-to-end locale-resolution scenario coverage"
```
