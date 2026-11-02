# Notifications Foundation (Phase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every notification an audience with its own inbox (member app, dashboard, provider portal), render its text in the reader's language, deliver it synchronously after commit, and target staff by permission — without changing what existing senders send.

**Architecture:** `GeneralNotification` stays the one Laravel notification; it stops queueing and goes through a custom `InboxChannel` that writes the `audience` column. Rows keep `template_key` + `params`, and `NotificationText` renders them for the request locale (inbox) or the device language (push). `NotificationService` is the only sender; it defers to `DB::afterCommit`, pushes only to the member audience, and nudges clients over Pusher.

**Tech Stack:** Laravel 12, Sanctum, spatie/laravel-permission, kreait FCM, Pusher broadcasting, PHPUnit on SQLite.

**Spec:** `docs/superpowers/specs/2026-11-02-notifications-design.md`

## Global Constraints

- PHP: `PHP="/c/laragon/bin/php/php-8.3.33-nts-Win32-vs16-x64/php.exe"`; run tests with `"$PHP" artisan test …`, style with `"$PHP" vendor/bin/pint`.
- Work on branch `feat/notifications` cut from `feat/hr-person-linking`. No commits — the owner decides at the end.
- Audiences are exactly `member`, `staff`, `provider`; legacy rows are `member`.
- Supported locales are `SetLocaleFromHeader::SUPPORTED_LOCALES` (`ar`, `en`); fallback order device → `users.preferred_language` → `config('app.locale')`.
- Push (FCM) goes to the `member` audience only. FCM data values are strings.
- Never pick recipients with `User::role(...)`; staff are reached by permission.
- Update endpoints answer `['message' => __('api.notification.…')]`; list responses keep content inside `data`.
- Existing `data` keys (`session_id`, `payment_id`, …) are unchanged.
- Known pre-existing failures in `tests/Feature/Notification` (Arabic-vs-English messages, `preferred_language` NOT NULL) are not ours — compare by test name.

## Review Focus

- A user who is both member and staff: a staff notice must not appear in `member/notifications` nor reach their phone. → Task 1 test `test_staff_notice_stays_out_of_member_inbox_and_off_the_phone`.
- A legacy row (no `params`) must still show its stored title/body, not raw `:space` placeholders. → Task 2 test `test_legacy_row_shows_its_stored_text`.
- A translatable param stored as `{ar,en}` must never print as `Array`. → Task 2 test `test_translatable_param_follows_the_request_language`.
- A device registered with no `lang` header falls back to the account language, not `null`. → Task 3 test `test_device_without_language_uses_account_language`.
- A notification sent inside a transaction that rolls back reaches nobody. → Task 4 test `test_rolled_back_change_sends_nothing`.

---

### Task 1: Audiences and the three inboxes

**Files:**
- Create: `database/migrations/2026_11_02_100000_add_audience_to_notifications_table.php`
- Create: `app/Domain/Identity/Enums/NotificationAudience.php`
- Create: `app/Domain/Identity/Models/InboxNotification.php`
- Create: `app/Notifications/Channels/InboxChannel.php`
- Create: `app/Http/Controllers/Concerns/ServesNotificationInbox.php`
- Create: `app/Http/Controllers/Api/V1/Admin/NotificationInboxController.php`
- Create: `app/Http/Controllers/Api/V1/Provider/ProviderNotificationController.php`
- Modify: `app/Notifications/GeneralNotification.php`, `app/Domain/Identity/Models/User.php`, `app/Http/Controllers/Api/V1/Member/MemberNotificationController.php`, `routes/api/v1/admin.php` (staff-only block near `me`), `routes/api/v1/provider.php`, `tests/Guards/AdminRoutesArePermissionGatedTest.php` (`STAFF_ONLY`)
- Test: `tests/Feature/Notification/NotificationInboxTest.php`

**Interfaces:**
- Produces: `enum NotificationAudience: string { Member='member'; Staff='staff'; Provider='provider'; public static function forAccount(User $user): self }` (staff when `getAllPermissions()->isNotEmpty()`).
- Produces: `InboxNotification extends DatabaseNotification` — cast `audience` to the enum, `scopeForAudience(Builder $q, NotificationAudience $a)`.
- Produces: `User::notifications(): MorphMany` → `InboxNotification`, `latest()`.
- Produces: `GeneralNotification::__construct(string $templateKey, array $placeholders = [], array $extraData = [], string|array|null $customTitle = null, string|array|null $customBody = null, NotificationAudience $audience = NotificationAudience::Member)`; no `ShouldQueue`; `via()` → `[InboxChannel::class]`; public readonly `$audience`.
- Produces: `InboxChannel extends DatabaseChannel` — `buildPayload()` adds `'audience' => $notification->audience->value`.
- Produces: trait `ServesNotificationInbox` with `abstract protected function inboxAudience(): NotificationAudience` and actions `index`, `markAsRead`, `markAllAsRead`, `unreadCount` (bodies moved from `MemberNotificationController`, every query scoped by `forAudience`).
- Produces: `NotificationService::sendToUser(…, NotificationAudience $audience = NotificationAudience::Member)` and the same last parameter on `sendToUsers`; push only when the audience is `Member`.

- [ ] **Step 1: Write the failing tests** in `NotificationInboxTest`:
  - `test_staff_inbox_lists_only_staff_notices` — a staff user (`assignRole('admin')`) holding one `Staff` and one `Member` row; `GET /api/v1/admin/notifications` (Sanctum `['dashboard']`) → `assertJsonCount(1, 'data')`.
  - `test_provider_inbox_lists_only_provider_notices` — same shape on `GET /api/v1/provider/notifications` for a provider account of an active provider.
  - `test_staff_notice_stays_out_of_member_inbox_and_off_the_phone` — user with roles `member` + `admin` and an `fcm_token`; `notify(new GeneralNotification('inquiry.new_received', [...], [], null, null, NotificationAudience::Staff))` via `NotificationService::sendToUser(..., audience: Staff)`; `GET /api/v1/member/notifications` (`['member-app']`) → count 0; `notification_logs` count 0.
  - `test_read_all_marks_only_this_inbox` — member + staff rows unread; `POST /api/v1/admin/notifications/read-all`; member row still unread.
  - `test_member_and_provider_accounts_cannot_open_the_staff_inbox` — member token → 403.
- [ ] **Step 2: Run** `"$PHP" artisan test tests/Feature/Notification/NotificationInboxTest.php` → FAIL (routes / class missing).
- [ ] **Step 3: Migration** — `notifications.audience` `string(20)` default `'member'` after `type`, index `['notifiable_type', 'notifiable_id', 'audience']`; backfill `audience = 'staff'` where `data` LIKE `%"template_key":"<key>"%` for `inquiry.new_received`, `membership.desk_needed`, `inventory.maintenance_due`, `inventory.warranty_ending`, `hr.alerts_digest`, `hr.approval_pending`, `hr.approval_escalated`, `hr.benefit_grants_failed`. `down()` drops index and column.
- [ ] **Step 4: Implement** the enum, model, `User::notifications()` override, channel, `GeneralNotification` changes, trait, the three controllers, and routes: admin `GET notifications`, `GET notifications/unread-count`, `PATCH notifications/{id}/read`, `POST notifications/read-all` each `->middleware('staff')`; same four on `provider.php` without extra middleware. Add the four admin routes to `STAFF_ONLY` and to the "only exceptions" comment above the `me` route. `MemberNotificationController` keeps its routes and response shapes.
- [ ] **Step 5: Run** the new test file plus `tests/Feature/Notification tests/Guards/AdminRoutesArePermissionGatedTest.php tests/Guards/ProviderRoutesStayIsolatedTest.php` → PASS (pre-existing failures excepted).

### Task 2: Text rendered when read

**Files:**
- Create: `app/Services/Notification/NotificationText.php`
- Modify: `app/Notifications/GeneralNotification.php` (`toArray`), `app/Http/Resources/NotificationResource.php`, `app/Services/Notification/NotificationService.php` (`sendCustomToUser`), `app/Http/Controllers/Api/V1/Admin/AdminNotificationController.php`
- Test: `tests/Feature/Notification/NotificationRenderingTest.php`

**Interfaces:**
- Produces (static, `final class NotificationText`):
  - `exists(string $templateKey): bool` — both `.title` and `.body` exist in every supported locale.
  - `resolveParams(array $params, string $locale): array` — a value that is an array keyed by locale becomes its `$locale` entry, else `en`, else `ar`, else first.
  - `render(string $templateKey, array $params, string $locale): array{title: string, body: string}`.
  - `translations(string $langKey, array $replace = []): array<string, string>` — the lang line in every supported locale (for params that are themselves lang lines).
- Produces: stored `data` = `{template_key, params, title, body, data}`; `title`/`body` are the snapshot in the account language; for `custom`, `title`/`body` are `{ar, en}` arrays and `params` is `[]`.
- Produces: `NotificationService::sendCustomToUser(User $user, array $title, array $body, array $extraData = []): void` — `$title`/`$body` keyed by locale.
- Produces: `NotificationResource` adds `template_key`; renders title/body for `app()->getLocale()`: `params` present and `NotificationText::exists` → `render`; array `title` → pick locale; otherwise the stored strings.

- [ ] **Step 1: Write the failing tests:**
  - `test_inbox_renders_in_the_request_language` — user `preferred_language='ar'`, `sendToUser($u, 'booking.approved', ['space' => 'Room A'])`; `GET member/notifications` with header `lang: en` → `data.0.title === 'Booking Approved'`; with `lang: ar` → `'تمت الموافقة على الحجز'`.
  - `test_translatable_param_follows_the_request_language` — params `['space' => ['ar' => 'غرفة أ', 'en' => 'Room A']]`; `lang: en` body contains `Room A`, `lang: ar` body contains `غرفة أ`, neither contains `Array`.
  - `test_legacy_row_shows_its_stored_text` — insert a row whose `data` is `{template_key:'booking.approved', title:'Old', body:'Old body', data:[]}` (no `params`) → resource title `Old`.
  - `test_broadcast_is_read_in_the_request_language` — admin broadcast `{en:'Hello', ar:'مرحباً'}` to a member; member inbox with `lang: en` → `Hello`, `lang: ar` → `مرحباً`.
  - `test_unknown_template_throws_outside_production` — `sendToUser($u, 'nope.missing')` → `LogicException`.
- [ ] **Step 2: Run** `"$PHP" artisan test tests/Feature/Notification/NotificationRenderingTest.php` → FAIL.
- [ ] **Step 3: Implement** `NotificationText`; `GeneralNotification::toArray` stores `params` and the snapshot via `NotificationText::render` (custom: arrays as given, snapshot not needed); the resource; `sendCustomToUser` and the broadcast controller (pass `$validated['title']` / `['body']` whole). In `NotificationService`, a missing template: `if (! NotificationText::exists($key))` → throw `LogicException` unless `app()->isProduction()`, where it is `report()`ed and sending continues.
- [ ] **Step 4: Run** the new file + `tests/Feature/Notification` → PASS.

### Task 3: Push in each device's language

**Files:**
- Create: `database/migrations/2026_11_02_100001_add_language_to_device_tokens_table.php`
- Modify: `app/Domain/Identity/Models/DeviceToken.php` (fillable), `app/Services/Notification/DeviceTokenService.php` (`register`), `app/Http/Controllers/Api/V1/Member/PreferencesController.php` (`updateLanguage`), `app/Services/Notification/NotificationService.php` (`sendPush`)
- Test: `tests/Feature/Notification/PushLanguageTest.php`

**Interfaces:**
- Produces: `device_tokens.language` `string(5)` nullable after `platform`.
- Produces: `DeviceTokenService::register()` fills `language = app()->getLocale()`; new `DeviceTokenService::setSessionLanguage(int $accessTokenId, string $language): void`.
- Produces: `sendPush(User $user, string $templateKey, array $params, array $data)` (private) — groups tokens by `language ?? user.preferred_language ?? app.locale`, renders per group, one `NotificationLog` per group (payload `title`, `body`, `data`, `device_count`, `language`). Legacy `users.fcm_token` fallback uses the account language. Custom notifications pass their per-locale title/body.

- [ ] **Step 1: Write the failing tests:**
  - `test_device_registers_with_the_request_language` — `PUT /api/v1/member/device-token` with header `lang: en` → `device_tokens.language = 'en'`.
  - `test_language_change_updates_this_device` — token registered on the session; `PATCH /api/v1/member/preferences/language` `{preferred_language: 'en'}` → that row `language = 'en'`.
  - `test_each_device_gets_its_own_language` — user `ar` with devices `ar` and `en`; `sendToUser($u, 'booking.approved', ['space' => ['ar' => 'غرفة أ', 'en' => 'Room A']])` → two `notification_logs`; the `en` one's `payload.title = 'Booking Approved'`, the `ar` one's `'تمت الموافقة على الحجز'`.
  - `test_device_without_language_uses_account_language` — device `language = null`, user `en` → one log, title `Booking Approved`.
- [ ] **Step 2: Run** `"$PHP" artisan test tests/Feature/Notification/PushLanguageTest.php` → FAIL.
- [ ] **Step 3: Implement** the migration, model, service and controller changes (`updateLanguage` calls `setSessionLanguage` when `currentAccessToken()` is a saved `PersonalAccessToken`).
- [ ] **Step 4: Run** the new file + `tests/Feature/Notification` → PASS.

### Task 4: After-commit delivery and the Pusher nudge

**Files:**
- Create: `app/Services/Notification/NotificationCreated.php`
- Modify: `app/Services/Notification/NotificationService.php`, `routes/channels.php`, `config/notifications.php` (drop `queue`)
- Test: `tests/Feature/Notification/NotificationDeliveryTest.php`

**Interfaces:**
- Produces: `NotificationCreated implements ShouldBroadcastNow` — `__construct(int $userId, NotificationAudience $audience)`; `broadcastAs(): 'notification.created'`; `broadcastWith(): ['audience' => …]`; `broadcastOn()`: `member.{id}` for `Member`, `dashboard.{id}` otherwise. Sent through `RealtimeBroadcaster`.
- Produces: channel `dashboard.{id}` — `(int) $user->id === (int) $id && $user->tokenCan('dashboard')`.
- Produces: every public send method of `NotificationService` runs its delivery inside `DB::afterCommit` (one private `deliver(User $user, GeneralNotification $n, string $templateKey, array $params, array $data)` writes the row, pushes when `Member`, nudges).

- [ ] **Step 1: Write the failing tests:**
  - `test_rolled_back_change_sends_nothing` — `sendToUser` inside `DB::transaction` that throws → `notifications` count 0, `notification_logs` count 0.
  - `test_notification_is_written_without_a_queue` — `Queue::fake()`; `sendToUser` → `notifications` count 1, `Queue::assertNothingPushed()`.
  - `test_member_notice_nudges_the_member_channel` / `test_staff_notice_nudges_the_dashboard_channel` — `Event::fake([NotificationCreated::class])`; assert dispatched with `broadcastOn()[0]->name` `private-member.{id}` / `private-dashboard.{id}`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** the file + `tests/Feature/Notification tests/Feature/Finance/PaymentNotificationsTest.php tests/Feature/Booking/CoworkingRealtimeTest.php` → PASS.

### Task 5: Staff by permission, and the guards

**Files:**
- Modify: `app/Services/Notification/NotificationService.php`, `app/Http/Controllers/Api/V1/Public/SiteInquiryController.php`, `app/Domain/Membership/Services/DedicatedDeskService.php`, `app/Domain/Hr/Adapters/AddHrNotifier.php`, `app/Domain/Inventory/Adapters/AddInventoryNotifier.php`
- Create: `tests/Guards/NotificationRecipientsComeFromPermissionsTest.php`, `tests/Guards/NotificationTemplatesHaveBothLanguagesTest.php`
- Test: `tests/Feature/Notification/NotificationTargetingTest.php`

**Interfaces:**
- Produces: `NotificationService::sendToHolders(string $permission, string $templateKey, array $placeholders = [], array $extraData = []): void` — active accounts from `User::permission($permission)`, `Staff` audience; `PermissionDoesNotExist` → nobody.
- Recipients: site inquiry → `site-inquiries.view`; desk needed → `memberships.create`; HR and Inventory `notifyHolders` → `sendToHolders`; HR `notifyEmployee` → `sendToUser(…, NotificationAudience::forAccount($user))`.

- [ ] **Step 1: Write the failing tests:**
  - `test_holders_of_the_permission_are_notified_whatever_their_role` — custom role `desk_clerk` with `site-inquiries.view`; `POST /api/v1/public/site-inquiries` (existing payload from `SiteInquiryTest`) → that user has one `staff` row; a `member` has none.
  - `test_unknown_permission_notifies_nobody` — `sendToHolders('nothing.here', 'inquiry.new_received', …)` → no rows, no exception.
  - `test_hr_employee_notice_follows_the_account` — `AddHrNotifier::notifyEmployee` for an employee whose user holds a permission → `staff`; for a plain member → `member`.
  - Guard `NotificationRecipientsComeFromPermissionsTest::test_no_notification_sender_picks_recipients_by_role` — every `app/**/*.php` mentioning `NotificationService` contains no `::role(`.
  - Guard `NotificationTemplatesHaveBothLanguagesTest::test_ar_and_en_hold_the_same_keys` (`Arr::dot` of both files, same key set) and `test_every_template_has_a_title_and_a_body` (every key ending `.title` has a non-empty sibling `.body` and vice versa).
- [ ] **Step 2: Run** → FAIL (guard fails on `SiteInquiryController`, `DedicatedDeskService`). **Step 3: Implement.** **Step 4: Run** the files + `tests/Feature/Ecosystem/SiteInquiryTest.php tests/Feature/Membership/DedicatedDeskAssignmentTest.php tests/Feature/Hr tests/Feature/Inventory/InventoryIntegrationTest.php --testsuite=Guards` → PASS.

### Task 6: Existing senders pass translatable params

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/Reception/ArrivalRequestController.php`, `.../SessionEndRequestController.php` (`'space' => $session->space->name`), `app/Domain/Membership/Services/DedicatedDeskService.php` (`'plan' => $membership->plan?->name ?? '#'.$membership->plan_id`), `app/Services/Experience/EventApprovalService.php` + `EventRegistrationService.php` (`'event' => $event->title`), `app/Domain/Finance/Payments/PaymentNotifier.php` (`'purpose' => NotificationText::translations('notifications.payment.purposes.'.$payment->purpose->value)`, drop its own locale lookup)
- Test: extend `tests/Feature/Booking/CoworkingSessionEndTest.php` and `tests/Feature/Finance/PaymentNotificationsTest.php` with one assertion each.

- [ ] **Step 1: Add assertions** — after a confirmed session end, the member's inbox read with `lang: en` has a body containing the space's English name; after `payment.received`, the stored `params.purpose` has both `ar` and `en`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** `tests/Feature/Booking/CoworkingSessionEndTest.php tests/Feature/Finance tests/Feature/Membership/DedicatedDeskAssignmentTest.php tests/Feature/Experience` → PASS.

### Task 7: Finish

- [ ] `"$PHP" vendor/bin/pint --dirty` then `--test` → clean.
- [ ] `"$PHP" artisan test tests/Feature/Notification --testsuite=Guards` and every file touched above → only pre-existing failures.
- [ ] One whole-branch review (`code-reviewer`), fix what it confirms.
