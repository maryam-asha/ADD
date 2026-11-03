# Dashboard Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ADD OS shows the signed-in account's real notifications (staff or provider inbox) with read state, live updates over Pusher, and Firebase browser notifications for the dashboard.

**Architecture:** The API already serves both inboxes and nudges `private-dashboard.{id}` with `notification.created`. ADD OS replaces the template's fake bell with an inbox module over those endpoints. Firebase adds a second kind of device token, `surface = dashboard`, which receives data-only pushes for the staff and provider audiences; app tokens keep the member audience. In ADD OS a plain service worker shows the push when no dashboard tab is visible.

**Tech Stack:** Laravel 12 + kreait FCM (ADDCore, branch `feat/dashboard-push` from `master`); Vue 3 + naive-ui + laravel-echo + `firebase` web SDK (ADDOS, branch `feat/dashboard-notifications` from `feat/hr-module`).

**Spec:** agreed in chat on 2026-11-03 (no separate design doc): the bell reads the real inbox, the unread count badge, live updates, a toast for a new notice, click marks read and opens the related page, mark all read, a drawer with load more, no delete, text in the UI language; Firebase browser notifications for staff and provider accounts, enabled by a button, not shown while a dashboard tab is visible, removed on sign-out, separate from member app tokens.

## Global Constraints

- Update endpoints return `{"message": ...}`; a response holding content in `data` keeps everything inside `data`.
- Admin routes check no role. The account's own token endpoints sit behind `staff`, like the inbox, and are added to the `STAFF_ONLY` allowlist in `AdminRoutesArePermissionGatedTest`.
- Push text is rendered server-side in each token's language; it is the same text the inbox shows.
- No queue: delivery stays inside `DB::afterCommit` in `NotificationService::deliver()`.
- ADD OS icons: Carbon names through `Icon.vue`, then `npm run icons` (`icons.spec.ts` fails otherwise). No emoji.
- ADD OS loads nothing external that is not on the `no-external-urls.spec.ts` allowlist, each entry with its reason. Firebase is loaded by dynamic import only when its five env values are set.
- ADD OS user-facing strings go in `src/add-os/lang/{ar,en}/{ar,en}.json` under `notifications`.

## Review Focus

1. Someone who is staff and also a member: work pushes must never reach their phone app, and member pushes must never reach their dashboard browser (Task 2).
2. One browser used by two accounts in turn: the token moves to the second account, and the first stops receiving (Task 1).
3. Signed out by an expired session (a 401) with the tab open: that browser stops receiving pushes (Task 6).
4. A push arriving while a dashboard tab is visible: no system notification, only the in-page toast (Task 6).
5. Switching language: the list re-renders in the new language, and the browser token is re-registered with it (Tasks 5 and 6).

---

## Part A — ADDCore

### Task 1: Dashboard device tokens

**Files:**
- Create: `database/migrations/2026_11_03_100000_add_surface_to_device_tokens_table.php`, `app/Domain/Identity/Enums/DeviceSurface.php`, `app/Http/Controllers/Concerns/RegistersDashboardDeviceToken.php`, `app/Http/Controllers/Api/V1/Admin/DashboardDeviceTokenController.php`, `app/Http/Controllers/Api/V1/Provider/ProviderDeviceTokenController.php`, `app/Http/Requests/Dashboard/DashboardDeviceTokenRequest.php`
- Modify: `app/Domain/Identity/Models/DeviceToken.php`, `app/Services/Notification/DeviceTokenService.php`, `app/Http/Controllers/Api/V1/Member/MemberDeviceTokenController.php`, `routes/api/v1/admin.php`, `routes/api/v1/provider.php`, `tests/Guards/AdminRoutesArePermissionGatedTest.php`
- Test: `tests/Feature/Notification/DashboardDeviceTokenTest.php`

**Interfaces:**
- Produces: `enum DeviceSurface: string { case App = 'app'; case Dashboard = 'dashboard'; }`; column `device_tokens.surface` string(20), default `app`, cast to `DeviceSurface`; `DeviceTokenService::register(User $user, string $token, array $details = [], ?int $accessTokenId = null, DeviceSurface $surface = DeviceSurface::App): void`; `DeviceTokenService::forgetAll(User $user, ?DeviceSurface $surface = null): void` (null = every surface); routes `PUT|DELETE api/v1/admin/device-token` (`staff`) and `PUT|DELETE api/v1/provider/device-token`, body `{token: string ≤500}`, the push language taken from the `lang` header as the app's is; responses use `api.notification.device_token_updated` and `api.notification.device_token_removed`.

- [ ] **Step 1: Write the failing tests** in `DashboardDeviceTokenTest`:
  - `test_staff_registers_a_dashboard_token`: a user holding `bookings.view` sends `PUT /api/v1/admin/device-token` `{token: "web-1", language: "ar"}` → 200; a row exists with `surface = dashboard`, `language = ar`, `access_token_id = null`; `users.fcm_token` is unchanged (null).
  - `test_an_account_without_permissions_is_refused`: an employee-only account → 403.
  - `test_provider_registers_on_its_own_route`: a provider account → `PUT /api/v1/provider/device-token` → a `dashboard` row.
  - `test_delete_forgets_only_that_browser`: two dashboard rows, `DELETE` with `{token: "web-1"}` → only `web-2` remains.
  - `test_member_app_tokens_stay_app`: `PUT /api/v1/member/device-token` → `surface = app`, and `users.fcm_token` is set as before.
  - `test_member_forget_all_leaves_dashboard_tokens`: an app row plus a dashboard row for one user; the member `DELETE` without a token removes only the app row.
  - `test_the_same_browser_moves_to_the_next_account`: staff A registers `web-1`, then staff B registers `web-1` → one row, owned by B.
- [ ] **Step 2: Run** `"$PHP" artisan test tests/Feature/Notification/DashboardDeviceTokenTest.php`. Expected: FAIL (the routes and the column do not exist yet).
- [ ] **Step 3: Implement.**
  - The migration adds `surface` after `platform`, with the index `['user_id', 'surface']`.
  - `register()` stores the surface. The install-row lookup and `users.fcm_token` touch app rows only, and `syncLegacyColumn()` reads app rows only.
  - `MemberDeviceTokenController::destroy()` calls `forgetAll($user, DeviceSurface::App)`.
  - The trait's `update()` calls `register($user, $token, ['language' => ...], null, DeviceSurface::Dashboard)`; its `destroy()` calls `forget()`.
  - The guard allowlist gains both admin routes.
- [ ] **Step 4: Run** the new test file, then `tests/Guards` and `tests/Feature/Notification`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(notifications): dashboard browsers register their own push tokens, apart from the member app's`

### Task 2: Push for the staff and provider audiences

**Files:**
- Modify: `app/Services/Notification/PushProvider.php`, `app/Services/Notification/Drivers/FcmPushProvider.php`, `app/Services/Notification/Drivers/MockPushProvider.php`, `app/Services/Notification/NotificationService.php`, plus any test class implementing `PushProvider`
- Test: `tests/Feature/Notification/DashboardPushTest.php`

**Interfaces:**
- Consumes: `DeviceSurface`, `device_tokens.surface` (Task 1).
- Produces: `PushProvider::sendDataToDevices(array $tokens, array $data): array<string,bool>`, a data-only message whose values are all strings. A dashboard push's `$data` holds `title`, `body`, `notification_id` (the inbox row id), `audience` (`staff`|`provider`), `template_key`, and `target_type`/`target_id` when the notice has them.

- [ ] **Step 1: Write the failing tests** with a recording fake `PushProvider` bound in `setUp`:
  - `test_staff_notice_pushes_data_only_to_dashboard_tokens`: a staff user with one app token `app-1` and one dashboard token `web-1` (language `ar`). Sending `sendToUser(..., NotificationAudience::Staff)` makes exactly one data call, to `['web-1']`. Its data has `title`/`body` equal to the inbox row rendered in `ar`, `notification_id` equal to the row's id, and `audience = staff`. No notification-style call is made.
  - `test_provider_notice_pushes_to_dashboard_tokens`: the same check for `NotificationAudience::Provider`.
  - `test_member_notice_never_reaches_a_dashboard_browser`: a member notice to that user sends a notification push to `['app-1']` only.
  - `test_legacy_fallback_ignores_dashboard_tokens`: a user with only a dashboard row and `fcm_token = 'legacy-1'`. A member notice goes to `['legacy-1']`; a staff notice goes to `['web-1']`.
  - `test_broadcast_skips_accounts_with_only_dashboard_tokens`: `broadcast()` does not create a member inbox row for a staff user who has only a dashboard token.
- [ ] **Step 2: Run** the file. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - In `deliver()`, set `$notification->id ??= (string) Str::uuid()` before `notify()`, so the inbox row and the push share one id.
  - Member: `sendPush()` reads app rows (legacy fallback unchanged).
  - Staff/provider: a new private `sendDashboardPush()` groups dashboard rows by language, calls `sendDataToDevices()`, and logs to `notification_logs` the way `pushTo()` does.
  - `broadcast()` counts app rows only.
  - In `FcmPushProvider::sendDataToDevices()`, build `CloudMessage::new()->withData($data)->withWebPushConfig(WebPushConfig::fromArray(['headers' => ['Urgency' => 'high', 'TTL' => '86400']]))` and send it as a multicast; the results follow `sendToDevices()`.
- [ ] **Step 4: Run** `tests/Feature/Notification`, then the full suite. Expected: PASS, apart from the known pre-existing failures.
- [ ] **Step 5: Commit** `feat(notifications): staff and provider notices reach dashboard browsers through Firebase`

---

## Part B — ADDOS

### Task 3: Inbox services, targets and relative time

**Files:**
- Modify: `src/add-os/services/notifications.ts`, `src/add-os/services/api.ts` (only if `del` cannot send a body), `src/add-os/utils/format/dates.ts`
- Create: `src/add-os/notifications/targets.ts`
- Test: `src/add-os/notifications/__tests__/targets.spec.ts`, `src/add-os/utils/format/__tests__/relative.spec.ts`

**Interfaces:**
- Produces:
  - `type InboxAudience = "staff" | "provider"` (base path `/api/v1/admin` or `/api/v1/provider`).
  - `interface InboxNotification { id: string; template_key: string | null; title: string | null; body: string | null; data: Record<string, string> | null; read_at: string | null; created_at: string }`.
  - `fetchInbox(audience, page = 1): Promise<{ data: InboxNotification[]; meta: { current_page: number; last_page: number; per_page: number } }>`.
  - `fetchUnreadCount(audience): Promise<number>`, read from `data.unread_count`.
  - `markNotificationRead(audience, id): Promise<void>` and `markAllNotificationsRead(audience): Promise<void>`.
  - `registerBrowserToken(audience, token): Promise<void>` (PUT; the `lang` header carries the language) and `removeBrowserToken(audience, token): Promise<void>` (DELETE through `delWithBody`, with the token in the body and never in the URL).
  - `notificationTarget(audience, data): string | null` and `notificationIcon(templateKey): string`.
  - `timeAgo(value, now = Date.now()): { unit: "now" } | { unit: "minutes" | "hours" | "days"; count } | { unit: "date" }`. The wording lives in the language files as counted messages (`notifications.time.*` with `countKey`), because the format module forbids `Intl` for display.

| target_type | staff path | provider path |
|---|---|---|
| booking | `/bookings/all` | — |
| company | `/members/companies/{id}` | `/provider/companies` |
| event | `/community/events/{id}/attendees` | — |
| invoice, invoices | `/payments/invoices` | `/provider/invoices` |
| membership | `/members/memberships` | `/provider/companies` |
| office_lease | `/members/office-leases` | `/provider/office-leases` |
| payment | `/payments/transactions` | — |
| private_office_request | `/address/private-office-requests` | `/provider/office-requests` |
| provider_request | `/providers/requests` | `/provider/requests` |
| service_request | `/address/operations-requests` | — |
| wallet | `/plans/wallet` | — |
| exchange_rate_suggestion | `/payments/exchange-rates` | — |

- [ ] **Step 1: Write the failing tests.**
  - `targets.spec`: each row of the table; `{target_type: "company", target_id: "12"}` gives `/members/companies/12`; an unknown type, or no data at all, gives `null`.
  - `notificationIcon("booking.approved")` gives the booking icon, and an unknown group gives `carbon:notification`.
  - `relative.spec`, with a fixed `now`: 30 s ago in `en` is "now"; 5 min ago in `ar` contains "5" in Latin digits; 2 days ago is "2 days ago"; 10 days ago equals `formatDate(value)`.
- [ ] **Step 2: Run** `pnpm vitest run src/add-os/notifications src/add-os/utils/format`. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - `formatRelativeTime` uses `Intl.RelativeTimeFormat(\`${currentLocale.value}-u-nu-latn\`, { numeric: "auto" })` for anything under 7 days, and `formatDate` beyond that.
  - Icons by template group: `booking`, `access`, `reception`, `coworking`, `wallet`, `membership`, `company`, `invoice`, `renewal`, `lease`, `private_office`, `payment`, `event`, `service_request`, `provider_request`, `provider`, `exchange_rate`, `hr`, `inventory`. Pick existing Carbon icons and check each against `@iconify-json/carbon`.
- [ ] **Step 4: Run** the tests. Expected: PASS.
- [ ] **Step 5: Commit** `feat(notifications): inbox endpoints, where a notice leads, and relative times`

### Task 4: Live inbox state

**Files:**
- Modify: `src/add-os/services/realtime.ts`
- Create: `src/add-os/notifications/inbox.ts`
- Test: `src/add-os/notifications/__tests__/inbox.spec.ts`, plus an added case in `src/add-os/services/__tests__/realtime.spec.ts`

**Interfaces:**
- Consumes: Task 3.
- Produces:
  - `subscribeDashboardNotifications(userId: number, handler: () => void): () => void`, on the private channel `dashboard.{userId}`, event `.notification.created`.
  - `useInbox()` returns `{ items, unreadCount, hasMore, loading, audience, start(audience, userId), stop(), refresh(), loadMore(), markRead(id), markAllRead(), open(item), openFromPush(data) }`. The state is module-level and shared; `start()` is idempotent per user.

- [ ] **Step 1: Write the failing tests**, with the services and realtime mocked:
  - `start` loads the count and page 1.
  - An event triggers `refresh`, which toasts only the item not seen before.
  - The first load toasts nothing.
  - `markRead` decrements the count once and calls the API; marking it again neither decrements nor calls.
  - `markAllRead` sets the count to 0 and every `read_at`.
  - `loadMore` appends page 2 and sets `hasMore = false` on the last page.
  - Polls every 60 s only while `isRealtimeConnected()` is false (fake timers).
  - Changing `currentLocale` reloads page 1.
  - `stop` unsubscribes and clears timers.
  - realtime spec: the handler is called on `.notification.created` on `private-dashboard.7`.
- [ ] **Step 2: Run** the specs. Expected: FAIL.
- [ ] **Step 3: Implement.** The toast is `useGlobalActions().notification({ title, content: body, duration: 5000, keepAliveOnHover: true })`, at most 3 per refresh. `open(item)` marks the item read when it is unread, then does `router.push(target)` when there is a target.
- [ ] **Step 4: Run** the specs. Expected: PASS.
- [ ] **Step 5: Commit** `feat(notifications): the dashboard inbox stays current over Pusher, polling only while the socket is down`

### Task 5: The bell and the drawer

**Files:**
- Modify: `src/app-layouts/common/Toolbar/Notifications.vue`, `src/components/common/Notifications/List.vue`, `src/components/common/Notifications/Toolbar.vue`, `src/add-os/lang/{ar,en}/{ar,en}.json`, `src/add-os/assets/icons.generated.json` (via `npm run icons`)
- Delete: `src/composables/useNotifications.ts` (its only users are these three files)

**Interfaces:**
- Consumes: `useInbox()`, `notificationIcon`, `formatRelativeTime`.

- [ ] **Step 1: Implement.**
  - The bell renders only for `authStore.isStaff || authStore.isProvider`, and calls `start(audience, user.id)` on mount and `stop()` on unmount.
  - The badge shows the count, capped at `99+`, and is hidden at 0.
  - The popover lists the latest 7.
  - Each item shows an icon, a bold title when unread, the body (2 lines), the relative time, and an unread dot (click → `markRead`). Clicking the row → `open(item)`.
  - No delete buttons. The footer has "Mark all as read", disabled at 0.
  - The drawer shows the full list with a "Load more" button while `hasMore`, plus an empty state.
  - New i18n keys: `notifications.empty`, `notifications.loadMore`.
- [ ] **Step 2: Verify** with `pnpm vue-tsc --build --force`, `pnpm eslint src/app-layouts/common/Toolbar src/components/common/Notifications src/add-os/notifications`, and `pnpm vitest run src/add-os/assets`. Expected: no new errors.
- [ ] **Step 3: Commit** `feat(notifications): the toolbar bell reads the account's real inbox`

### Task 6: Firebase browser notifications

**Files:**
- Create: `public/notifications-sw.js`, `src/add-os/notifications/browserPush.ts`
- Modify: `package.json` (`firebase`), `.env.example`, `src/add-os/services/auth.ts` (`logout`), `src/stores/auth.ts` (`setLogout`), `src/add-os/notifications/inbox.ts` (push clicks), `src/components/common/Notifications/Toolbar.vue`, the lang files, `src/add-os/__tests__/no-external-urls.spec.ts`
- Test: `src/add-os/notifications/__tests__/browserPush.spec.ts`, `src/add-os/notifications/__tests__/notifications-sw.spec.ts`

**Interfaces:**
- Consumes: `registerBrowserToken` and `removeBrowserToken` (Task 3), `useInbox().openFromPush` and `refresh` (Task 4).
- Produces:
  - Env values: `VITE_FIREBASE_API_KEY`, `VITE_FIREBASE_PROJECT_ID`, `VITE_FIREBASE_MESSAGING_SENDER_ID`, `VITE_FIREBASE_APP_ID`, `VITE_FIREBASE_VAPID_KEY`. All five set means on; otherwise off.
  - `type BrowserPushState = "unsupported" | "unconfigured" | "default" | "granted" | "denied"`.
  - `browserPushState(): BrowserPushState` and `enableBrowserPush(audience): Promise<BrowserPushState>`.
  - `syncBrowserPush(audience): Promise<void>`.
  - `removeBrowserPush(audience): Promise<void>` (server, then local) and `forgetBrowserPushLocally(): Promise<void>`.
  - Remembered token key: `localStorage["add-os:push-token"]`.
  - Service worker messages: `{type: "add-os:notification"}` and `{type: "add-os:notification-click", data}`.
  - Opening from a closed tab: `/?notification=<id>&target_type=<t>&target_id=<i>`.

- [ ] **Step 1: Write the failing tests.**
  - `browserPush.spec`, with `firebase/app`, `firebase/messaging` and the services mocked:
    - Unconfigured → state `unconfigured`, and `enable` touches nothing.
    - Permission granted → registers `/notifications-sw.js` with scope `/`, calls `getToken` with `vapidKey` and that registration, calls `registerBrowserToken(audience, token, currentLocale)`, and stores the token.
    - Permission denied → no `getToken`.
    - `removeBrowserPush` → `removeBrowserToken` with the stored token, then the browser's push subscription is unsubscribed (not Firebase's `deleteToken`, which needs the registration from the same page session), then the stored token is cleared; a failing server call still clears it locally.
    - `syncBrowserPush` after a locale change → re-registers with the new language.
    - `setLogout()` → `forgetBrowserPushLocally` runs.
  - `notifications-sw.spec`, which evaluates `public/notifications-sw.js` against a fake `self` and `clients`:
    - A push with no visible client → `showNotification(title, { body, tag: id, data })`.
    - A push with a visible client → no `showNotification`, but `postMessage({type: "add-os:notification"})`.
    - A click with an open client → `focus()` plus `postMessage` with the data.
    - A click with no client → `openWindow("/?notification=n1&target_type=invoice&target_id=7")`.
    - A push without `data.title` → nothing.
- [ ] **Step 2: Run** both specs. Expected: FAIL.
- [ ] **Step 3: Implement.**
  - The service worker uses no imports: `install` calls `skipWaiting`, `activate` calls `clients.claim`, and it has `push` and `notificationclick` handlers.
  - `logout()` awaits `removeBrowserPush(audience)` before the server logout, ignoring errors. `setLogout()` calls `forgetBrowserPushLocally()` by dynamic import.
  - Inbox `start()` calls `syncBrowserPush`. It handles `?notification=` once (`openFromPush`, then `router.replace` without the query) and listens for service worker messages: a click → `openFromPush`, a notice → `refresh`.
  - In the popover footer: state `default` shows the button "Enable browser notifications"; `denied` shows a muted line saying they are blocked in the browser settings.
  - Build, run the URL guard, and allowlist each Firebase host it reports, with its reason.
- [ ] **Step 4: Run** both specs, `no-external-urls.spec`, the type-check and lint. Expected: PASS.
- [ ] **Step 5: Commit** `feat(notifications): Firebase browser notifications for staff and providers when no dashboard tab is open`

### Task 7: Check it running

- [ ] Start the ADD OS dev server against the local API. Sign in as staff, create a staff notice (for example a pending booking), and check that the badge, the list, the toast, mark read, mark all read, load more, the click target and the language switch all work. Take a screenshot.
- [ ] Without Firebase values: no browser-notification button, and no Firebase request in the network log.
