# خطة: نظام دفع موحّد لكل العمليات

> **للمنفّذين:** يُنفَّذ مهمةً مهمة (superpowers:subagent-driven-development). كل مهمة تبدأ باختبار يفشل ثم الكود ثم commit. الكود والتعليقات والتوثيق في المستودع بالإنجليزية، والتواصل مع المستخدمة بالعربية.

## السياق

عمليات الشراء اليوم أربع: شراء باقة، حجز، اشتراك بفعالية، شحن رصيد، ويُضاف إليها مبلغ جلسة العمل المشترك. كل عملية تدفع بطريقتها الخاصة:
- **الحجز:** يُخصم من المحفظة لحظة الإنشاء، وإن لم يكفِ الرصيد يُنشأ «غير مدفوع» بصمت، ولا يمكن أن يطلب العضو «كاش» صراحةً.
- **الفعالية:** تُخصم من المحفظة الشخصية فقط عند الموافقة، ولا يوجد تسجيل حضور.
- **الباقة:** تُدفع من المحفظة أو بالبطاقة، ولا يوجد كاش.
- **Paymera:** مربوطة مباشرة في الكود بلا أي طبقة مجردة، وتدعم الشحن وشراء الباقة فقط.
- **تسوية الاستقبال:** تغيّر حالة الحجز أو الجلسة فقط، دون أي سجل للمبلغ المستلم، فلا يراها دفتر الصندوق.
- **الاسترداد:** يعود دائماً إلى المحفظة الشخصية، حتى لو دفعت محفظة الشركة.

**المطلوب:** كل عملية يمكن دفعها بثلاث طرق، مع إمكانية ربط أي طريقة جديدة بالنظام دون تعديل العمليات:
- **النقاط:** مع فحص كفاية الرصيد.
- **الكاش عند الاستقبال.**
- **Paymera.**

**قرارات المستخدمة:**
- **الحجز:** يُثبَّت دون دفع ويُدفع لاحقاً.
- **الفعالية:** تُثبَّت دون دفع، لكن **لا حضور دون دفع**، مع تسجيل حضور جديد عند الباب يرفض غير الدافع.
- **الباقة بالكاش:** طلب معلّق لا تُفعَّل فيه الباقة حتى يؤكد الاستقبال استلام المبلغ، ويُلغى إن لم يُدفع خلال مهلة.
- **حالة الدفع:** ظاهرة بوضوح في كل عملية (مدفوع أو غير مدفوع أو بانتظار).
- **عند الاستقبال:** يُدخل الموظف المبلغ المستلم ويؤكد، وأي **زيادة تُضاف نقاطاً إلى محفظة العضو**.
- **المحافظ:** الفعاليات والباقات تُدفع من المحافظ **مثل الحجوزات**، أي الشخصية أو محفظة شركة العضو، ويختار العضو إن توفرت أكثر من واحدة.
- **مبلغ جلسة العمل المشترك:** داخل النظام، فيُدفع من التطبيق بالنقاط أو Paymera، أو كاش عند الاستقبال.

## المبادئ

1. **النقاط هي الدفتر الوحيد.** أي مال يدخل بالكاش أو بأي بوابة يتحول أولاً إلى نقاط في المحفظة، ثم تُصرف النقاط على العملية. هذا ما يفعله شراء الباقة بالبطاقة اليوم، ويحفظ ثوابت نظام النقاط (Invariants 1–5 في `docs/superpowers/specs/2026-09-12-add-point-system-design.md`).
2. **جدول `payments` واحد لكل دفعة، بأي طريقة كانت،** مع ربط متعدد الأشكال (morph) بالعملية التي دُفعت. هو مصدر «هل دُفع؟ بماذا؟ من أي محفظة؟».
3. **طرق الدفع برامج تشغيل (drivers) خلف واجهة واحدة.** إضافة طريقة جديدة تعني class وسطراً في الإعدادات، فتظهر تلقائياً في قائمة الطرق.
4. **كل غرض دفع له منفّذ (fulfiller)** يعرف كم يُستحق وماذا يحدث بعد الدفع. طرق الدفع لا تعرف شيئاً عن الحجوزات أو الفعاليات.
5. **التوافق مع الإصدار الحالي من التطبيق:**
   - `POST member/payments` بلا `method` يبقى Paymera كما هو.
   - رابط الـcallback `/api/v1/paymera/callback/` لا يتغير.
   - إنشاء الحجز بلا `payment_method` يبقى على سلوكه الحالي.
   - مسارات `settle-payment` القديمة تبقى تعمل.

## التصميم

### ١. طرق الدفع (`App\Domain\Finance\Payments`)
- **واجهة `PaymentMethodDriver`:**
  - `key()`
  - `supports(PaymentPurpose)`
  - `start(Payment, array $context): PaymentStart`، ونتيجتها أحد ثلاثة: `Settled` أو `Redirect(url)` أو `AwaitingReception`.
- **واجهة `PaymentGateway`** للبوابات، وتمتد من السابقة:
  - `create(Payment): CreatedPayment`
  - `status(Payment): PaymentStatusResult`
  - `cancel(Payment)`
- **البرامج المبدئية:**
  - `WalletMethod`
  - `CashMethod`: الدفع عند الاستقبال.
  - `PaymeraGateway`: يغلّف `PaymeraClient` الحالي دون تغيير في سلوكه.
- **`PaymentMethodRegistry`:** يُبنى من `config/payments.php` (`methods.<key>.enabled`)، على نمط `OtpServiceProvider`.
- **استبدال `PaymeraClient` بالبوابة:** `SyncPaymentStatus` و`ReversePayment` و`paymera:reconcile` تأخذ البوابة من `payment->method` عبر السجل بدل الاعتماد على `PaymeraClient` مباشرة. قفل `paymera:payment:{id}` يصبح `payments:payment:{id}`.
- **طرق الاستقبال:** قيم `PaymentMethod` الحالية (`cash`/`sham`/`mtn`/`syriatel`) تبقى وتصف «كيف استلم الاستقبال المال»، وتُحفظ في `payments.collected_via`.

### ٢. تعميم جدول `payments`
migration جديدة، والصفوف القديمة تُملأ بـ`method = paymera`. الأعمدة الجديدة:
- `method`: string(20)، عليه index.
- `payable_type` و`payable_id`: nullableMorphs.
- `amount_points`: ما تستحقه العملية بالنقاط، وللشحن يساوي `points_to_credit`.
- `debit_transaction_id`: قيد الصرف من المحفظة.
- `collected_via`، و`received_amount` (decimal)، و`received_currency`، و`received_by`، و`received_at`.
- `expires_at`: مهلة الطلب المعلّق.

**تصبح nullable:** `terminal_id`، و`callback_token`، و`amount_minor`، و`currency`، و`currency_exponent`، لأنها خاصة بالبوابة.

**قيم `PaymentPurpose` الجديدة:** `booking`، و`event_registration`، و`coworking_session`.

**تُضاف `Payment` إلى `EnumColumnsHaveBackedEnumCastsTest`**، فهي غير مسجلة فيه اليوم، وهذه فجوة موجودة.

### ٣. المنفّذ والخدمة المركزية (`PaymentService`)
- **واجهة `PaymentFulfiller`:** `pointsDue(Payment)`، و`walletCategory()`، و`fulfil(Payment)`.
- **منفّذ لكل غرض:** `WalletTopUp`، و`PlanPurchase` (يفعّل الباقة عبر `MembershipActivationService::activate`)، و`Booking`، و`WalkinSession`، و`EventRegistration`.
  - منفّذ الفعاليات يعيش في `app/Services/Experience`، احتراماً لـ`DomainLayerBoundaryTest`.
- **`PaymentService::start(purpose, payer, method, context)`:** ينشئ صف الدفعة ثم يستدعي برنامج الطريقة.
- **`PaymentService::settle(Payment)`:** مكان واحد يحوّل المال إلى نقاط، ثم يصرف النقاط، ثم يستدعي `fulfil`، ثم يعلّم العملية «مدفوعة». كل ذلك في معاملة واحدة مع قفل.
  - `FulfilAcceptedPayment` يصبح غلافاً رقيقاً فوقها.
  - فشل التفعيل بعد قبض مال البطاقة يبقى `needs_review`، كما هو اليوم.
- **تحويل المال المستلم إلى نقاط:** يُستخرج من `WalletTopUpController::paymentInBaseCurrency` وحساب النقاط في `store()` إلى خدمة واحدة (bcmath بدل float). تستخدمها تعبئة الاستقبال والتسوية معاً.

### ٤. الطرق الثلاث في كل عملية

| العملية | نقاط | كاش | Paymera |
|---|---|---|---|
| **حجز** (`POST member/bookings`، و`/batch`) | خصم فوري، ورصيد غير كافٍ ← 422 مع العجز | يُثبَّت «غير مدفوع» ويُدفع عند الاستقبال | يُثبَّت، ثم تبدأ دفعة ويعود `redirect_url`، وعند القبول يصبح مدفوعاً، وعند الفشل يبقى غير مدفوع |
| **فعالية** (`POST member/events/{event}/register`) | خصم عند التثبيت (فوراً، أو عند الموافقة إن احتاجتها) | تُثبَّت غير مدفوعة، **ولا حضور قبل الدفع** | تُثبَّت، ثم يدفع العضو (فوراً، أو بعد الموافقة عبر «ادفع الآن») |
| **باقة** (`POST member/memberships`) | كما اليوم | **دفعة معلّقة** (`status=pending`، `expires_at`) دون إنشاء اشتراك، ويؤكد الاستقبال فتُفعَّل الباقة، وتنتهي المهلة بأمر مجدول | مسار البطاقة الحالي |
| **شحن رصيد** | — | عند الاستقبال فقط (موجود) | موجود |
| **جلسة عمل مشترك** (مبلغ ما بعد الإغلاق) | من التطبيق | عند الاستقبال | من التطبيق |

- **حقل `payment_method` الجديد:** يُضاف إلى طلبات الحجز والفعالية والباقة، بالقيم `wallet` أو `cash` أو `paymera` أو أي طريقة مفعّلة. معه `wallet_owner_type/id` كما في الحجز اليوم.
- **حجز بلا `payment_method`:** يبقى على المنطق الحالي `routePayment` للتوافق.
- **سلوك `wallet` الصريح:** إما يدفع الآن، وإما 422 `insufficient_points` مع `current` و`required` و`shortage`. كما يُغلق هذا باب الخطأ 500 الحالي حين يسبق نقصُ الرصيد الخصم.
- **فحص الكفاية** يبقى عبر `WalletService::spendOptions`، مع تصحيح `usable_balance` ليطابق ما يفعله `debit()` فعلاً (الرجوع إلى الرصيد العام).

### ٥. أعمدة الدفع على العمليات
- **`bookings` و`walkin_sessions`:** يُضاف `price_points` (ما يُستحق بالنقاط)، ويُكتب عند الإنشاء أو الإغلاق بجانب `amount_owed` الحالي.
- **`event_attendees`:** يُضاف `price_points` (لقطة وقت التسجيل)، و`payment_state`، و`payment_method`، و`paid_at`، و`checked_in_at`، و`checked_in_by`.
- **الاسترداد:** إلغاء الحجز أو رفضه يسترد مجموع `amount_points` لدفعاته المقبولة، **إلى المحفظة التي دفعت فعلاً** (`payment.wallet_id`). هذا يصلح خطأ محفظة الشركة، ويُلغي إعادة حساب السعر وقت الإلغاء.

### ٦. خدمات التطبيق
- **`GET member/payment-methods?purpose=&payable_id=&plan_id=`:** الطرق المتاحة لهذه العملية.
  - المحافظ مع `sufficient`.
  - الكاش إن كان مسموحاً للغرض.
  - Paymera وأي بوابة مفعّلة.
- **`POST member/payments`:** يُوسَّع بـ`method`، و`payable_id` للحجز أو الفعالية أو الجلسة.
  - يعمل «ادفع الآن» لأي عملية غير مدفوعة.
  - الرد: `{payment, redirect_url, next: redirect|done|pay_at_reception}`.
- **كتلة `payment` في موارد التطبيق:** `{state: paid|unpaid|pending, method, amount_points, price, price_point, paid_at, payment_id}`، في:
  - الحجوزات (القائمة والتفاصيل).
  - فعالياتي وتفاصيل الفعالية.
  - `memberships/current`، ومعها `pending_purchase`.
  - الجلسة الحالية وتقريرها.

### ٧. خدمات الاستقبال واللوحة
- **`GET admin/reception/outstanding?user_id=`:** كل ما على العضو من غير مدفوع أو معلّق (حجوزات، جلسات، فعاليات، طلبات باقات)، بالنقاط وبالعملة.
- **`POST admin/reception/payments/settle`:** الحقول `{purpose, payable_id | payment_id, collected_via, amount, currency}`.
  - يسجّل المال في الدفتر مع `performed_by`، ويدفع العملية، ويضيف الزيادة نقاطاً إلى المحفظة، ويُشعر العضو.
  - المبلغ الناقص ← 422 مع قيمة العجز.
  - `settle-payment` القديمان يتحولان إلى غلافين فوقه، بلا `amount` يعني المبلغ المستحق تماماً.
- **`POST admin/events/{event}/attendees/{attendee}/check-in`:** يرفض بـ409 `event_unpaid` إن كانت الفعالية مدفوعة ولم تُدفع. يستطيع الاستقبال التسوية كاشاً ثم تسجيل الحضور.
- **تعبئة الاستقبال:** تكتب صف `payments` أيضاً (`method = cash`، `purpose = wallet_top_up`)، ليجتمع كل المال في سجل واحد.
- **`admin/payments`:** فلاتر `method` و`purpose`.
- **`CashRegisterService::closeSession`:** يجمع `payment_amount` (مالاً) لا `amount` (نقاطاً). وتدخل التسويات فيه تلقائياً لأنها صارت تكتب قيوداً.

### ٨. الإشعارات
- `payment.received`: للعضو عند أي تسوية أو قبول دفعة.
- `payment.expired`: عند انتهاء طلب باقة معلّق.
- `event.payment_due`: عند تثبيت فعالية غير مدفوعة.
- جميعها عبر `NotificationService::sendToUser`، مع قوالب en/ar.
- يُضاف أيضاً سجل تدقيق لتسجيل العضو في فعالية، وهو غائب اليوم.

## المهام

- **0.** worktree `feat/unified-payments` من `feat/add-point-system` (`b438894`)، وقياس الأساس (17 فشلاً سابقاً معروفاً).
- **1.** الواجهتان والسجل و`PaymeraGateway` و`config/payments.php`، مع إعادة ربط Sync وReverse وReconcile بالسجل. **بلا تغيير سلوك**، وكل اختبارات `tests/Feature/Finance/Paymera*` تبقى خضراء.
- **2.** migration جدول `payments`، والنموذج، والتعدادات (`PaymentPurpose` جديدة)، والحراس (guards).
- **3.** `PaymentService` والمنفّذون (شحن وباقة أولاً)، ومحوّل المال إلى نقاط. `FulfilAcceptedPayment` يستخدمها.
- **4.** `WalletMethod` ومنفّذا الحجز والجلسة، و`price_points` على الحجوزات والجلسات، و`payment_method` في إنشاء الحجز والدفعة المتعددة، والاسترداد إلى المحفظة الدافعة.
- **5.** `CashMethod`: الحجز غير المدفوع، وطلب الباقة المعلّق، والأمر المجدول لانتهاء المهلة.
- **6.** Paymera لأغراض الحجز والفعالية والجلسة، و«ادفع الآن» عبر `POST member/payments`.
- **7.** الفعاليات: الأعمدة، والتسجيل بطريقة دفع، ومسار الموافقة، وتسجيل الحضور الرافض لغير الدافع، والتدقيق.
- **8.** تسوية الاستقبال الموحدة، و`outstanding`، وغلافا `settle-payment`، والزيادة إلى المحفظة، وصف `payments` لتعبئة الاستقبال.
- **9.** `member/payment-methods` وكتلة `payment` في الموارد.
- **10.** إصلاح دفتر الصندوق.
- **11.** الإشعارات.
- **12.** التوثيق:
  - `docs/decisions/unified-payments.md`.
  - تحديث `docs/paymera-mobile-api.md` (عربي).
  - تحديث `CLAUDE.md`.
  - تحديث Postman: مجلد «الدفع» للعضو والاستقبال، مع أمثلة حقيقية ملتقطة كما فعلنا.
  - ثم التحقق الكامل.

## الملفات الأساسية (للقراءة قبل التنفيذ)

| الموضوع | الملفات |
|---|---|
| البوابة | `app/Domain/Finance/Services/{StartPayment,SyncPaymentStatus,ReversePayment,PaymeraClient,PaymentAmountCalculator}.php`، `app/Listeners/Finance/*`، `app/Console/Commands/ReconcilePaymeraPayments.php`، `config/paymera.php` |
| الجدول | `database/migrations/2026_09_26_100000_create_payments_table.php` |
| المحفظة | `app/Domain/Membership/Services/WalletService.php` (`spendOptions`، `debit`، `debitGeneral`، `creditGeneral`) |
| الحجز | `BookingCreationService::routePayment`، `BookingBatchCreationService`، `BookingCancellationService`، `BookingApprovalService`، `BookingPresentationService::paymentMethods` |
| الجلسات | `SessionClosureService::{settlePayment,finalizeClosure}` |
| الفعاليات | `app/Services/Experience/{EventRegistrationService,EventApprovalService}.php`، `app/Domain/Ecosystem/Models/EventAttendee.php` |
| الباقات | `MembershipActivationService::activate`، `Member/MembershipController::store` |
| الاستقبال | `Admin/Reception/WalletTopUpController.php`، `SettlePaymentRequest`، `CashRegisterService` |

## قيود يجب احترامها
- **الحراس:** `MoneyIsDecimalOnlyTest`، و`NoNewMysqlEnumColumnsTest` (string مع cast)، و`EnumColumnsHaveBackedEnumCastsTest`، و`PointConversionIsCentralizedTest`، و`WalletBalanceIgnoresAuditColumnsTest`، و`NetworkIsolationTest`، و`DomainLayerBoundaryTest`.
- **المستمعون (listeners):** تُكتشف تلقائياً ولا تُسجَّل يدوياً، لأن التسجيل اليدوي يُطلقها مرتين.
- **العملات:** كل تحويل يمر عبر `PointConversionService`. لا float في المال. الخصم يُقرَّب للأعلى والإضافة للأسفل.
- **MorphMap:** لا يُضاف فيه `User` أبداً، وأي اسم مستعار جديد يرافقه migration لإعادة كتابة `activity_log`.
- **قاعدة البيانات:** لا `migrate` على قاعدة MySQL من الـworktree. التطبيق على قاعدة المستخدمة يكون بعد الدمج، مع نسخة احتياطية أولاً كما فعلنا.

## قرارات افتراضية أتخذها (تُراجع في التسليم)
- **الاسترداد يكون نقاطاً في المحفظة،** حتى لما دُفع بالبطاقة، ولا يُعاد إلى البطاقة. هذا هو السلوك الحالي.
- **مهلة طلب الباقة المعلّق:** 48 ساعة، في إعداد `payments.cash_plan_hold_hours`.
- **الفعالية المدفوعة والملغاة:** تبقى بلا استرداد، كما هو قرار `event-approval-and-custom-forms.md`، وتُسجَّل كمتابعة لاحقة.
- **تمديد الحجز:** يسجّل صف دفعة عند الخصم، لكن إصلاح انقلاب الحجز كله إلى «غير مدفوع» عند نقص الرصيد يبقى متابعة منفصلة.

## التحقق
- **لكل مهمة:** اختبارها أولاً، ثم اختبارات المجالات التي لمستها. Paymera عبر `Http::fake` كما في الاختبارات الحالية.
- **سيناريوهات إلزامية:**
  1. حجز بالنقاط بمحفظة الشركة ثم إلغاؤه يعيد النقاط إلى **الشركة**.
  2. حجز بالكاش يظهر «غير مدفوع»، ثم تسوية باستقبال بمبلغ أكبر: الحجز مدفوع، والزيادة في المحفظة، والصندوق يرى المبلغ.
  3. فعالية غير مدفوعة: تسجيل الحضور 409، ثم تسوية ثم حضور 200.
  4. باقة بالكاش: لا اشتراك قبل التأكيد، وبعده تُفعَّل، وانتهاء المهلة يجعلها `expired` بلا اشتراك.
  5. Paymera لحجز: `redirect_url`، ثم القبول يجعل الحجز مدفوعاً، والرفض يبقيه غير مدفوع.
  6. التطبيق الحالي دون تغيير: `POST member/payments` القديم، وحجز بلا `payment_method`، و`settle-payment` القديم، كلها كما هي.
  7. برنامج دفع وهمي يُسجَّل في الإعدادات يظهر في `payment-methods` ويعمل دون تعديل أي عملية.
- **الختام:** الحزمة كاملة مقابل الأساس (لا فشل جديد)، و`pint --test`، و`route:list`، واختبار يدوي عبر Postman.
