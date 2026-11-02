# Notifications, end to end — 2026-11-02

**For:** ADD Core (`App\Services\Notification`, `lang/{ar,en}/notifications.php`, member /
admin / provider inbox routes) and the clients that read them (member app, ADD OS
dashboard, provider portal). Builds on the FCM push already wired
(`NotificationService`, `DeviceTokenService`) and the Pusher channels
(`routes/channels.php`, `RealtimeBroadcaster`).

## Why

The owner wants one complete notification system: every moment a member, a staff member
or a provider should hear about, with its exact wording, delivered where that person
works. Today the plumbing exists but the system around it does not:

- Seven senders only write a `notification_logs` row marked `sent` and reach nobody
  (booking approved / rejected / lapsed, booking pending approval, event registration
  approved / rejected, provider request approved / rejected). `booking.lapsed` and
  `event.registration_*` have no text at all.
- About 25 templates in `lang/*/notifications.php` have no sender.
- The in-app row goes through the `notifications` queue, which nothing consumes: the dev
  script's `queue:listen` reads `default` only and the shared host runs no worker. Pushes
  arrive; inbox rows sit in `jobs`.
- Staff and providers have no inbox, so staff notifications are written for nobody to
  read; and staff recipients are picked by role name (`operations`, `admin`), which the
  2026-10-27 permission rule forbids.
- A notification's text is frozen in the account's language when it is sent, so a device
  showing English can receive Arabic (the known multi-device language gap).

## The model

### Three audiences, one table

Every notification belongs to one **audience**, stored on the row
(`notifications.audience`):

| Audience | Who | Where it is read | Push |
|---|---|---|---|
| `member` | a member (a company admin is a member) | member app: `member/notifications` | FCM, every device of the account |
| `staff` | holders of a permission | dashboard: `admin/notifications` | none — Pusher nudge on `private-dashboard.{id}` |
| `provider` | a provider's accounts | provider portal: `provider/notifications` | none — Pusher nudge on `private-dashboard.{id}` |

Each inbox lists only its own audience. One person who is both a member and staff sees
the staff notices in the dashboard and never on their phone, which keeps the member
surface and the dashboard sealed from each other (`member-auth-hybrid.md` §11). Legacy
rows default to `member`; rows whose key is a staff-only key are backfilled to `staff`.

The member app is also nudged on its existing `private-member.{id}` channel
(`notification.created`), so an open app refreshes its badge without waiting for the push.

### Text rendered when read, in the reader's language

A row stores `template_key`, `params` and the routing `data` — plus a `title`/`body`
snapshot in the account's language, used only when the template no longer exists.
`NotificationResource` renders title and body at read time in the request's locale (the
`lang` header). A param may itself be translatable (`{"ar": "…", "en": "…"}`, e.g. a
space name) and resolves to the same locale. An admin broadcast stores both languages and
shows the reader's.

Push is rendered per device: `device_tokens.language` is recorded from the request
locale whenever a device registers (login, register, `PUT member/device-token`) and
updated on `PATCH member/preferences/language` for the device making the change. Devices
are grouped by language and each group gets its own text. Fallback: device language →
`users.preferred_language` → `config('app.locale')`.

### Delivery

- Synchronous, no queue: the inbox row is written in the request, the push and the
  Pusher nudge follow it. The host runs no worker, and the realtime events already work
  this way.
- After commit: the service itself defers delivery with `DB::afterCommit`, so a
  rolled-back change tells nobody, wherever the caller sits.
- A failed push or nudge is logged and never fails the request.
- A missing template is a bug: the service throws outside production and reports in
  production.

### Targeting

- `sendToUser` / `sendToUsers` — member audience.
- `sendToHolders(permission, …)` — staff audience: every active account holding the
  permission through any role or directly. No branch filter: staff are not branch-scoped
  (`rbac-scoping.md`). Never `User::role(...)` — guarded.
- `sendToProvider(provider, …)` — provider audience: the provider's accounts.
- HR `notifyEmployee` goes to the staff audience when the employee's account is a staff
  account (holds any permission), otherwise to the member audience.

### Routing data

`data` carries what the app needs to open the right screen. Existing keys stay as they
are (the app reads them). New notifications add `target_type` and `target_id` as strings
(FCM data values must be strings), e.g. `booking` / `123`.

### Rules every sender keeps

- No notification for something the user just did themselves and already saw the result
  of (paying from the wallet, cancelling their own booking).
- A door code never appears in a notification's text — the notice says it is ready; the
  app shows it.
- Reminders are sent once per subject and stage (a `dedupe_key` on `notification_logs`,
  unique per user), built in phase 3 with the first reminder.

## The catalog

Arabic wording below is the approved text; English is written alongside it in each
phase. `:x` are params. Status: **live** (works today), **fake** (logs only),
**template** (text exists, no sender), **new**.

### Member — bookings

| Key | When | Title — body | Status |
|---|---|---|---|
| `booking.approved` | staff approved | تمت الموافقة على حجزك — حجزك لـ :space يوم :date الساعة :time مؤكد. | fake |
| `booking.rejected` | staff rejected | لم تتم الموافقة على حجزك — رُفض حجزك لـ :space يوم :date، وأُعيد :points نقطة إلى محفظتك. | fake |
| `booking.lapsed` | start reached while pending | لم يُؤكَّد حجزك — حلّ موعد حجزك لـ :space قبل الموافقة عليه، وأُعيد المبلغ إلى محفظتك. | fake |
| `booking.cancelled` | reception cancelled it | تم إلغاء حجزك — ألغى فريق ADD حجزك لـ :space يوم :date، وأُعيد :points نقطة إلى محفظتك. | template |
| `booking.payment_expired` | prepayment hold lapsed | أُلغي حجزك لعدم الدفع — انتهت مهلة دفع حجزك لـ :space، وأصبح الموعد متاحاً لغيرك. | new |
| `booking.reminder` | 30 min before start | حجزك يبدأ قريباً — يبدأ حجزك في :space الساعة :time. امسح رمز الكيوسك عند وصولك. | template |
| `booking.ending_soon` | 10 min before end | حجزك ينتهي بعد :minutes دقائق — ينتهي حجزك في :space الساعة :time. يمكنك التمديد من التطبيق إن كانت المساحة متاحة. | new |
| `booking.no_show` | ended, never checked in | فاتك حجزك — انتهى حجزك لـ :space يوم :date دون تسجيل حضور. | new |
| `booking.affected_by_maintenance` | space entered emergency maintenance | حجزك متأثر بأعمال صيانة — دخلت :space صيانة طارئة، وسيتواصل معك فريقنا بخصوص حجزك. | template |

### Member — doors

| Key | When | Title — body | Status |
|---|---|---|---|
| `access.passcode_ready` | door code issued (cancellation window closed) | رمز دخولك جاهز — رمز باب :space جاهز من :from حتى :to. افتح التطبيق لعرضه. | template |
| `access.revoked` | code revoked for maintenance | أُوقف رمز الدخول — أُوقف رمز باب :space بسبب صيانة طارئة. | template |
| `access.office_opened` | lease started, members with door access | مكتبك جاهز — أصبح باب :space متاحاً لك من اليوم. | new |
| `company.door_access_changed` | door access granted / removed | تحديث صلاحية الأبواب — تم :status صلاحيتك لفتح أبواب مكاتب :company. | template |

### Member — coworking

| Key | When | Title — body | Status |
|---|---|---|---|
| `coworking.session_started` | reception confirmed the scan | بدأت الجلسة — أكّد الاستقبال وجودك. بدأت جلستك في :space. | live |
| `coworking.start_rejected` | reception refused | لم تبدأ الجلسة — لم يؤكد الاستقبال جلستك. يرجى مراجعة مكتب الاستقبال. | live |
| `coworking.session_ended` / `_unpaid` | reception confirmed the end | انتهت الجلسة — اضغط لعرض التقرير / لاختيار طريقة الدفع. | live |
| `coworking.arrival_expired` | arrival request expired unanswered | لم يُستلم طلبك — لم يؤكد الاستقبال وصولك خلال المهلة. امسح الرمز مجدداً أو راجع المكتب. | new |
| `coworking.closed_by_system` | forgotten session closed | أُغلقت جلستك تلقائياً — أُغلقت جلستك في :space الساعة :time لأن الخروج لم يُسجَّل. المستحق: :points نقطة. | new |
| `coworking.unpaid_reminder` | 24 h after an unpaid end | جلسة غير مدفوعة — ما زال عليك :points نقطة لجلسة :date. اضغط للدفع. | new |

### Member — payments and wallet

| Key | When | Title — body | Status |
|---|---|---|---|
| `payment.received` | card accepted later / paid at the desk | تم استلام الدفعة — تم استلام دفعتك مقابل :purpose (:points نقطة). | live |
| `payment.expired` | cash hold lapsed | تم إلغاء حجز الباقة — لم تُدفع الباقة في الاستقبال خلال المهلة. | live |
| `payment.failed` | card failed / expired after the member left | لم تكتمل عملية الدفع — لم تتم عملية الدفع مقابل :purpose، ولم يُخصم شيء. يمكنك المحاولة مجدداً. | new |
| `payment.reversed` | an admin reversed a payment | تم عكس دفعة — عُكست دفعتك مقابل :purpose بقيمة :points نقطة. تواصل معنا لأي استفسار. | new |
| `wallet.company_allocated` | company admin allocated points | وصلك رصيد من شركتك — خصّصت :company لك :points نقطة لاستخدامها في :category. | template |
| `hr.benefit_granted` | HR benefit credited | وصلتك ميزة — أُضيفت إلى محفظتك :amount نقطة من :benefit. | live |

### Member and company admin — plans

| Key | When | Title — body | Status |
|---|---|---|---|
| `membership.assigned` | activated by staff, company or provider | تم تفعيل باقتك — أصبحت باقة :plan مفعّلة لك من :start حتى :end. | template |
| `membership.started` | a future-dated plan started, balances granted | بدأ اشتراكك — بدأ اشتراكك في :plan اليوم وأُضيفت أرصدته إلى محفظتك. | new |
| `membership.expiring_soon` | 7 days and 1 day before, only without auto-renew | باقتك تنتهي قريباً — ينتهي اشتراكك في :plan بتاريخ :date. جدّد من التطبيق لتحافظ على أرصدتك. | template |
| `membership.expired` | period ended | انتهت باقتك — انتهى اشتراكك في :plan. تصفّح الباقات لتجديد اشتراكك. | template |
| `membership.renewed` | auto-renewed | تم تجديد الاشتراك — جُدّد اشتراك :plan حتى :date. | new |
| `membership.desk_assigned` | dedicated desk tied | مكتبك المخصص جاهز — خُصّص لك المكتب :desk في :space. | new |

### Member and company admin — company, leases, invoices

| Key | When | Title — body | Status |
|---|---|---|---|
| `company.member_joined` | added to a company | تمت إضافتك إلى :company — أصبحت عضواً في فريق :company في ADD. | template |
| `company.member_removed` | removed from a company | أُزلت من :company — لم تعد عضواً في فريق :company. | new |
| `company.admin_assigned` / `admin_removed` | company admin flag changed | تعيينك مديراً لـ :company — مُنحت صلاحيات إدارة :company وأعضائها ومحفظتها. | template |
| `invoice.issued` | invoice issued to the payer | فاتورة جديدة — صدرت الفاتورة :number بقيمة :amount :currency، وتستحق في :due. | new |
| `invoice.overdue` | open past its due date | فاتورة متأخرة — تجاوزت الفاتورة :number موعد استحقاقها. المتبقي: :amount :currency. | new |
| `lease.contracted` | lease signed | تم توقيع عقد المكتب — عقد :space يبدأ :start وينتهي :end. | new |
| `lease.ending_soon` | 30 days before, without auto-renew | عقد مكتبك ينتهي قريباً — ينتهي عقد :space بتاريخ :date. | new |
| `lease.ended` / `terminated` | lease over | انتهى عقد المكتب — تتوقف صلاحية باب :space من :date. | new |

### Member — events, services, account

| Key | When | Title — body | Status |
|---|---|---|---|
| `event.registration_approved` | approved | تم قبول تسجيلك — مقعدك في :event يوم :date مؤكد. | fake |
| `event.registration_rejected` | rejected | لم يُقبل تسجيلك — نعتذر، لم يُقبل تسجيلك في :event. | fake |
| `event.payment_due` | seat confirmed, payment pending | دفعة مستحقة — تم تأكيد مقعدك في :event. ادفع :points نقطة قبل الفعالية. | live |
| `event.registration_expired` | payment window lapsed | انتهت مهلة تسجيلك — لم يُدفع تسجيلك في :event خلال المهلة وأُلغي المقعد. | new |
| `event.reminder` | 1 day and 2 hours before | فعاليتك تقترب — :event يبدأ :date الساعة :time في :location. | new |
| `service_request.status_updated` | in progress / resolved / rejected | تحديث على طلبك — أصبحت حالة طلب :service: :status. | template |
| `account.password_changed` | password changed | تم تغيير كلمة المرور — إن لم تكن أنت، تواصل معنا فوراً. | new |
| `announcement` | admin broadcast | free text | live |

### Staff — by permission

| Key | Permission | Title — body | Status |
|---|---|---|---|
| `booking.pending_approval` | `reception.approve` | حجز بانتظار موافقتك — :member طلب :space يوم :date الساعة :time. | fake |
| `coworking.closed_by_system` | `reception.view` | جلسة أُغلقت تلقائياً — أُغلقت جلسة :member في :space لعدم تسجيل الخروج. | new |
| `event.registration_pending` | `events.manage_attendees` | تسجيل بانتظار الموافقة — :member سجّل في :event. | new |
| `service_request.submitted` | `service-requests.update` | طلب خدمة جديد — :member طلب :service. | new |
| `provider_request.submitted` | `provider-requests.approve` | طلب جديد من :provider — طلب :type لشركة :company. | new |
| `private_office.requested` | `private-office-requests.view` | طلب مكتب خاص جديد — من :name لـ :seats مقعداً. | new |
| `membership.desk_needed` | `memberships.create` | مكتب مخصص بانتظار الربط — اشترك عضو في :plan ويحتاج مقعداً. | live (by role) |
| `inquiry.new_received` | `site-inquiries.view` | استفسار جديد من الموقع — من :sender_name بخصوص :subject. | live (by role) |
| `payment.needs_review` | `payments.reverse` | دفعة تحتاج مراجعة — دفعة البطاقة رقم :id بقيمة :amount تحتاج مراجعة يدوية. | new |
| `renewal.stopped` | `renewals.manage` | توقف تجديد تلقائي — توقف تجديد :subject لـ :company: :reason. | new |
| `invoice.overdue_digest` | `invoices.view` | فواتير متأخرة — :count فواتير تجاوزت موعد استحقاقها. | new |
| `exchange_rate.suggested` | `exchange-rates.create` | سعر صرف مقترح — سعر جديد لـ :currency: :rate. راجعه واعتمده. | new |
| `hr.*`, `inventory.*` | per notice | unchanged | live |

Kiosk arrival requests and session-end requests stay Pusher-only on `private-reception`:
they are too frequent for an inbox.

### Provider

| Key | When | Title — body | Status |
|---|---|---|---|
| `provider.request_approved` | ADD approved | تمت الموافقة على طلبك — وافقت ADD على طلب :type لشركة :company. | fake |
| `provider.request_rejected` | ADD rejected | رُفض طلبك — رفضت ADD طلب :type لشركة :company. السبب: :reason | fake |
| `invoice.issued` / `overdue` | provider is the payer | as above | new |
| `invoice.payment_recorded` | payment recorded | تم تسجيل دفعة — سُجّلت :amount :currency على الفاتورة :number. المتبقي :remaining. | new |
| `provider.employee_joined` | invited employee accepted | انضم موظف جديد — انضم :name إلى :company. | new |
| `renewal.done` / `stopped`, `lease.ending_soon` | for its companies | as above | new |

## Phases

1. **Foundation** — audiences and the three inboxes, read-time rendering, per-device push
   language, synchronous after-commit delivery, Pusher nudge, targeting by permission
   (`sendToHolders`, used by the existing staff senders and the HR / Inventory adapters),
   template and recipient guards. Existing senders keep working unchanged.
2. **Fake to real, and the immediate notices** — the seven fake senders, then bookings
   cancelled / payment expired / no-show, doors, company membership, payment failed /
   reversed, service request status. A guard then forbids writing `notification_logs`
   outside the service.
3. **Reminders** — `dedupe_key`, then the scheduled ones: booking reminder and ending,
   event reminder, plan started / expiring / expired, lease ending, invoice overdue,
   unpaid session.
4. **Staff and provider notices** — the new staff and provider rows above.
5. **Later** — per-category mute for non-critical notices; WhatsApp for critical notices
   when the member has no device (block / deactivate revoke every device, so push cannot
   reach them).

## Calls made by Claude (check these)

- Push goes to the member audience only; staff and providers get the Pusher nudge and the
  inbox. Dashboard web push can come later.
- The language file is the catalog: one key per notice, `title` + `body` in both
  languages, guarded for parity. No separate enum.
- `dedupe_key` moved from phase 1 to phase 3, where its first caller lands.
- Admin broadcasts stay in the member audience whatever the target role.
- HR employee notices follow the account: staff account → dashboard, otherwise the app.
