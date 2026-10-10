# تصميم: عقود إيجار المكاتب الخاصة (المرحلة 3)

**الحالة:** معتمد ومنفّذ (الخطة docs/superpowers/plans/2026-10-11-office-leases.md). **المالكة:** Maryam Asha.
**يبني على:** `docs/superpowers/specs/2026-10-08-service-providers-design.md` (المرحلة 1) و`docs/superpowers/specs/2026-10-10-provider-invoicing-design.md` (المرحلة 2)، وكلاهما منفّذ.
الكود والتعليقات والتوثيق في المستودع بالإنجليزية، والتواصل مع المستخدمة بالعربية.

## السياق

مسار طلبات المكاتب الخاصة اليوم (`private_office_requests`: `requested → quoted → contracted`) ينتهي بإنشاء **الشركة ومحفظتها فقط** (`Admin\CompanyController::store`). لا شيء يربط الشركة بمكتب، ولا مدة، ولا سعر؛ `contract_ref` رقم نصّي فقط. المكاتب نفسها موجودة كمساحات `space_type = business` وطريقة تخصيصها `AllocationModel::Tenancy`، و`PasscodeIssuanceService::issueForTenancy()` موجودة لكن **لا يستدعيها أحد**، فأعضاء الشركة لا يأخذون رمز باب مكتبها.

هذه المرحلة تضيف **العقد**: شركة تستأجر مكتباً لمدة بسعر، تصدر عنه فواتير دورية بنظام المرحلة 2، ويفتح لأعضائها باب المكتب طوال مدته. تشمل **كل الشركات** — المباشرة وشركات المزوّدين. التجديد التلقائي في المرحلة 4، والتقارير في المرحلة 5.

## قرارات المستخدمة

| # | القرار |
|---|---|
| 1 | **عقد لكل مكتب.** شركة تستأجر مكتبين لها عقدان مستقلان، لكلٍّ مدته وسعره. |
| 2 | **فترة الدفع يختارها الموظف** لكل عقد: شهرياً، كل 3 أشهر، كل 6 أشهر، أو دفعة واحدة لكامل المدة. |
| 3 | **كل عقد يأتي من طلب مكتب.** الشركة الجديدة كما اليوم؛ الشركة الموجودة تطلب مكتباً إضافياً **بطلب جديد في نفس القائمة** مربوط بها، بنفس المراحل. المزوّد يطلب لشركاته بالطريقة نفسها. |
| 4 | **العقد يكمل حتى نهايته عادةً.** يوجد **إنهاء استثنائي** حين تسمح شروط العقد، بصلاحية مستقلة وسبب إلزامي: يتوقف الباب، يتحرر المكتب، لا تصدر الفواتير القادمة، والصادرة تبقى. الغرامة إن وُجدت يصدرها الموظف يدوياً، خارج هذا التصميم. |
| 5 | **لا تأمين** (للتبسيط). |
| 6 | **الدافع في شركات المزوّدين** يحدده المزوّد في الطلب (المزوّد أو الشركة)، وللموظف تعديله عند إنشاء العقد. الشركة المباشرة تدفع لنفسها دائماً. |
| 7 | **الفاتورة الأولى عند إنشاء العقد**، وكل فاتورة تالية **في أول يوم من فترتها**. الاستحقاق = مدة المزوّد أو الإعداد العام (15 يوماً). |
| 8 | **التأخر عن الدفع لا يوقف شيئاً آلياً.** تظهر الفاتورة متأخرة (موجود من المرحلة 2: `Invoice::scopeOverdue()`) والموظف يتابع. |
| 9 | **المزوّد** يرى عقود شركاته كاملة للقراءة (مع الأسعار والفواتير). **مدير الشركة** في تطبيق الأعضاء يرى المكتب والمدة والحالة **دون أسعار**. |
| 10 | **سعر الإيجار مبلغ ثابت بعملة** يختارها الموظف (مثلاً 500 USD شهرياً)، لا نقاط. كل فاتورة بنفس المبلغ مهما تغيّر سعر الصرف. |

## نموذج البيانات

### `office_leases` (جديد)
| عمود | النوع | ملاحظة |
|---|---|---|
| `id` | | |
| `reference` | string unique | `LSE-2026-00007` عبر `HasReference` كما في الفواتير |
| `company_id` | FK restrictOnDelete | |
| `space_id` | FK restrictOnDelete | مساحة `business` |
| `private_office_request_id` | FK unique restrictOnDelete | الطلب الذي أنتج العقد: طلب واحد ← عقد واحد |
| `provider_id` | FK nullable nullOnDelete | مزوّد الشركة لحظة الإنشاء (لقوائم البوابة والتصفية) |
| `billed_to` | string + enum `Payer` | للشركة المباشرة دائماً `company` |
| `currency` | string(3) FK `currencies.code` | |
| `monthly_amount` | decimal(14,2) | > 0، بدقة العملة |
| `starts_on` | date | |
| `duration_months` | unsignedSmallInteger | ≥ 1 |
| `billing_cycle_months` | unsignedSmallInteger | 1 أو 3 أو 6 أو `duration_months` (دفعة واحدة)؛ `duration_months % billing_cycle_months = 0` |
| `ends_on` | date | محسوب ومخزَّن: `starts_on + duration_months` (بلا تجاوز نهاية الشهر) − يوم |
| `status` | string + enum `OfficeLeaseStatus` | `active` · `ended` · `terminated` |
| `terminated_on` · `terminated_by` · `termination_reason` | nullable | الإنهاء الاستثنائي |
| `created_by` | FK users nullable | |
| `timestamps` | | |

فهارس: `(space_id, status)`، `(company_id, status)`، `(provider_id, status)`، `(status, ends_on)`.

**ثلاث حالات مخزّنة فقط** (ميل المستخدمة لمجموعات حالات صغيرة): «يبدأ في …» ليست حالة — عقد `active` و`starts_on > اليوم`. «ينتهي خلال 30 يوماً» تصفية على `ends_on`، لا حالة.

**نهاية الوصول الفعلية:** `terminated_on` إن وُجد، وإلا `ends_on`. يُشتق في النموذج (`accessEndsOn()`).

### `office_lease_periods` (جديد)
| عمود | النوع | ملاحظة |
|---|---|---|
| `id` | | |
| `office_lease_id` | FK cascadeOnDelete | |
| `sequence` | unsignedSmallInteger | 1..n، unique مع العقد |
| `starts_on` · `ends_on` | date | |
| `amount` | decimal(14,2) | `monthly_amount × billing_cycle_months` |
| `status` | string + enum `LeasePeriodStatus` | `upcoming` · `invoiced` · `cancelled` |
| `timestamps` | | |

فهرس `(status, starts_on)` للمهمة اليومية. الفاتورة مربوطة من جهة الفاتورة (أدناه)، لا من هنا.

**حساب التواريخ:** الفترة رقم k (من 0) تبدأ في `starts_on->addMonthsNoOverflow(k × cycle)` — تُحسب دائماً من تاريخ بداية العقد، **لا متسلسلة** من الفترة السابقة، حتى لا ينزلق يوم 31 إلى 28 ويبقى هناك. نهاية الفترة = بداية التالية − يوم؛ نهاية الأخيرة = `ends_on`. مثال: عقد يبدأ 31 كانون الثاني شهرياً ← فترات تبدأ 31/1، 28/2 (أو 29)، 31/3، 30/4، …

### تعديلات على جداول موجودة
- **`private_office_requests`:**
  - `company_id` FK nullable: الطلب لشركة موجودة (مكتب إضافي). فارغ = طلب شركة جديدة كما اليوم.
  - `payer` string nullable + enum `Payer`: يُملأ لطلبات المزوّدين، فارغ لغيرها.
- **`invoices`:**
  - `office_lease_period_id` FK nullable **unique** restrictOnDelete: المصدر الثاني للفواتير (بنمط عمود لكل مصدر، قرار المرحلة 2). الـ unique هو ما يمنع تكرار فاتورة الفترة.
  - `points` يصبح **nullable**: فاتورة الإيجار مبلغ بالعملة مباشرة. في فاتورة الإيجار `points = null` و`computed_amount = amount = period.amount`، و`exchange_rate_to_base` = سعر العملة يوم الإصدار (1 للأساسية)، للمرجع فقط.
- **`access_grants`:**
  - `office_lease_id` FK nullable nullOnDelete: يربط رمز الباب بعقده. (`source_id` الحالي مقيّد بـ `bookings` فلا يصلح.)

**ملاحظة على المرحلة 2:** قرارها الأول «الفواتير لمسار المزوّدين فقط» يتّسع هنا: فواتير الإيجار تصدر للشركات المباشرة أيضاً. الجدول يدعم ذلك أصلاً (`provider_id` nullable). تبقى الفواتير خارج تطبيق الأعضاء.

### قواعد ثابتة
- **لا تداخل:** لا عقدان `active` لنفس المكتب بتواريخ وصول متداخلة. يُفحص داخل المعاملة بعد `lockForUpdate` على صف المساحة، فطلبان متزامنان لنفس المكتب يُرفض ثانيهما.
- **المساحة:** `space_type = business` و`status = active` (ليست `maintenance`/`retired`).
- **المكتب المؤجَّر** يختفي من قائمة المكاتب المتاحة في `SpaceBrowseService` طوال مدة وصول عقده.
- مكتب في الصيانة: لا يُصدر له رمز؛ رموز قفله تُلغى كما لأي حجز، ويُعاد الرمز في أول تشغيل يومي بعد عودته.

## التدفق

### تقديم الطلب
- **شركة جديدة:** لا تغيير.
- **شركة موجودة:** `company_id` في الطلب. من لوحة التحكم يختار الموظف الشركة. من بوابة المزوّد (`POST provider/office-requests`) يقبل الطلب `company_id` (يجب أن تكون من شركات المزوّد — وإلا 404 كأنها غير موجودة، بنمط `provider.manage-company`) و`payer` (إلزامي لطلبات المزوّد).
- المراحل كما هي: `requested → quoted → contracted`.

### إنشاء العقد (تحويل `quoted` إلى `contracted`)
خدمة واحدة `OfficeLeaseService::contract(PrivateOfficeRequest, LeaseTerms, ?Company, User)` تُستدعى من مدخلين:

1. **`POST admin/companies`** (الحالي، لطلب بلا `company_id`): يأخذ مع بيانات الشركة حقول العقد: `space_id`، `starts_on`، `duration_months`، `billing_cycle_months`، `monthly_amount`، `currency`، و`billed_to` (لطلبات المزوّد؛ افتراضه `payer` الطلب). **فرع الشركة = فرع المكتب**؛ حقل `branch_id` الحالي يُشتق من المساحة ولا يُطلب.
2. **`POST admin/private-office-requests/{request}/lease`** (جديد، لطلب فيه `company_id`): حقول العقد فقط و`contract_ref`. المكتب يمكن أن يكون في **أي فرع**.

**في معاملة واحدة:** (الشركة ومحفظتها إن كانت جديدة) ← قفل المساحة وفحص التداخل ← العقد ← كل فتراته ← **فاتورة الفترة الأولى، وفاتورة كل فترة بدأت قبل اليوم (عقد بتاريخ سابق)** عبر `InvoiceIssuer` ← الطلب `contracted` مع `contract_ref` و`converted_company_id`. ثم **بعد** نجاح المعاملة: إن كان `starts_on ≤ اليوم` يصدر رمز الباب فوراً (فشل خدمة الأقفال لا يُبطل العقد؛ المهمة اليومية تعيد المحاولة).

**الرفض (422 برسالة مترجمة في `lang/{en,ar}/api.php` تحت `lease`):**
- الطلب ليس `quoted`.
- المدخل الخطأ: طلب فيه `company_id` على `POST admin/companies`، أو طلب بلا شركة على `/lease`.
- المساحة ليست `business`، أو ليست `active`.
- المكتب مؤجَّر بتواريخ متداخلة (409).
- `duration_months` لا يقبل القسمة على `billing_cycle_months`، أو الفترة ليست 1/3/6/المدة.
- العملة غير معرّفة، أو المبلغ ≤ 0، أو بدقة أكبر من دقة العملة.
- الشركة (المختارة أو الموجودة) غير `active`.

### إصدار الفواتير
`InvoiceIssuer` يكسب مدخلاً مستقلاً عن النقاط: `InvoiceDraft` يحمل إما `points` أو **مبلغاً ثابتاً بعملة** (`fixedAmount` + `currency`). مع المبلغ الثابت لا تحويل؛ `quote()` يستعمله كما هو ويسجّل سعر صرف اليوم للمرجع. غلاف رفيع `issueForLeasePeriod(OfficeLeasePeriod, ?User)`:
- **الوصف:** `Office lease {lease.reference}: {space name}, {starts_on} – {ends_on}`.
- **الدافع:** `lease.billed_to`؛ `provider_id` = `lease.provider_id`.
- **الاستحقاق:** تاريخ الإصدار + مدة المزوّد أو الإعداد العام (القائم في المرحلة 2).
- **`issued_by`:** الموظف لفاتورة الفترة الأولى؛ `null` لما تصدره المهمة اليومية (العمود nullable أصلاً)، ويُسجَّل النشاط بلا فاعل.
- تتحوّل الفترة إلى `invoiced` في نفس المعاملة.

الدفع والإبطال والإلغاء بلا تغيير (`InvoicePaymentService`). **إلغاء فاتورة إيجار لا يمسّ الفترة ولا العقد** (كما أن إلغاء فاتورة الباقة لا يمسّ الباقة): الفترة تبقى `invoiced`، ولا تُعاد إصدارها آلياً.

### المهمة اليومية `leases:run-daily`
في `routes/console.php`: `dailyAt('06:00')->timezone('Asia/Damascus')->withoutOverlapping()`. كل خطوة مستقلة، وكل عقد في معاملته الخاصة: فشل عقد لا يوقف الباقي.

1. **إصدار الفواتير:** كل فترة `upcoming` و`starts_on ≤ اليوم` (أياً كانت حالة العقد: فترات ما بعد الإنهاء أصبحت `cancelled` فلا تُختار) ← قفل الفترة، التأكد أنها ما زالت `upcoming`، إصدار الفاتورة. الفترات الفائتة (المهمة متوقفة أياماً) تصدر كلها في أول تشغيل، كلٌّ بتاريخ اليوم. الـ unique على `invoices.office_lease_period_id` هو الضمان الأخير ضد التكرار.
2. **فتح الباب:** كل عقد غير `ended` و`starts_on ≤ اليوم ≤ accessEndsOn()` (يشمل عقداً `terminated` بتاريخ مستقبلي)، وليس له رمز فعّال ← إصدار رمز على قفل المكتب.
3. **إنهاء العقود:** كل عقد `active` تجاوز `ends_on` ← `ended`.
4. **إغلاق الباب:** كل رمز مربوط بعقد تجاوز نهاية وصوله (`ended`، أو `terminated` وبلغ `terminated_on`) وما زال فعّالاً ← إلغاؤه على القفل.

الخطوتان 2 و4 تبحثان عن **حالة غير متّسقة** لا عن حدث، فأي فشل لخدمة الأقفال (`TTLockClient`) يُسجَّل ويُعاد تلقائياً في اليوم التالي.

### الدخول من الباب
تعديل `PasscodeIssuanceService::issueForTenancy()` لتأخذ العقد (`issueForLease(OfficeLease)`):
- **مربوط بالعقد** (`office_lease_id`) والقفل من `lockFor($lease->space)`.
- **يصدر مفعّلاً** (`status = activated`، `activated_at = now`، `must_activate_by` غير ذي معنى): رموز الحجوزات تحتاج تأكيد وصول من الكشك خلال 24 ساعة وإلا يسقطها `expireOverdue()`؛ هذا لا يصح لمكتب مؤجَّر. يجب أن يتخطى `expireOverdue()` رموز العقود.
- **الصلاحية حتى نهاية الوصول:** `expires_at` = نهاية يوم `accessEndsOn()`، ونافذة TTLock كذلك (بدل 5 سنوات).
- **من يدخل:** `UnlockService` يقبل اليوم منحة الشركة لأعضائها الذين `door_access_enabled = true` — بلا تغيير. لا رمز لوحة مفاتيح في أي استجابة لتطبيق الأعضاء (قرار مقفل: LockCredentialsNeverReachMemberTest)؛ الأعضاء يفتحون الباب من التطبيق.
- **مكتب بلا قفل:** لا يُرفض العقد؛ لا يصدر رمز، وتعرض استجابة الإدارة `access_warning: "no_lock"`.
- **الإلغاء:** دالة `revokeForLease(OfficeLease)` تحذف الرمز من TTLock وتجعله `revoked`.

### الإنهاء الاستثنائي
`POST admin/office-leases/{lease}/terminate` بـ `terminated_on` و`reason`، صلاحية `office-leases.terminate`. تحت قفل صف العقد:
- `terminated_on` هو **أول يوم بلا دخول**: الدخول حتى اليوم السابق له. المسموح: من تاريخ البداية حتى تاريخ النهاية. تُلغى الفترات التي تبدأ في terminated_on أو بعده.
- يُرفض إن لم يكن `active`، أو `terminated_on` قبل `starts_on` أو بعد `ends_on`.
- `status = terminated` مع التاريخ والفاعل والسبب.
- كل فترة `upcoming` **تبدأ في** `terminated_on` أو بعده ← `cancelled`. ما يبدأ في `terminated_on` أو قبله ولم يصدر بعد (إنهاء بتاريخ مستقبلي) يصدر في موعده كالمعتاد.
- إن كان `terminated_on ≤ اليوم`: إلغاء الرمز فوراً (بعد المعاملة؛ الفشل تعيده المهمة). وإلا تلغيه المهمة صباح ذلك اليوم.
- الفواتير الصادرة تبقى. يُسجَّل `lease_terminated` في سجل النشاط.

## الواجهات والصلاحيات

**صلاحيات جديدة** في `PermissionSyncService` (قائمة يدوية، بنمط صلاحيات المزوّدين والفواتير): `office-leases.view`، `office-leases.create`، `office-leases.terminate`. مسارات العقود في `admin.php` تخرج من فحص `role:admin|operations` (`withoutMiddleware`) وتفرض `permission:` لكل مسار، كما Branches والمزوّدين. الأدمن يأخذها عند `permissions:sync`.

### لوحة التحكم
| الواجهة | الصلاحية | ماذا تفعل |
|---|---|---|
| `GET admin/office-leases` | `office-leases.view` | قائمة مرقّمة؛ تصفية: `company_id`، `provider_id`، `space_id`، `branch_id`، `status`، `ending_within_days` |
| `GET admin/office-leases/{lease}` | `office-leases.view` | العقد + فتراته + فاتورة كل فترة (المرجع، الحالة، المدفوع، متأخرة) + حالة رمز الباب + `access_warning` |
| `POST admin/private-office-requests/{request}/lease` | `office-leases.create` | عقد لشركة موجودة؛ يردّ 201 بالعقد |
| `POST admin/companies` | كما اليوم | يأخذ حقول العقد الأول؛ يردّ الشركة مع عقدها |
| `POST admin/office-leases/{lease}/terminate` | `office-leases.terminate` | الإنهاء الاستثنائي؛ يردّ رسالة (`api.lease.terminated`) |

تفاصيل الشركة (`GET admin/companies/{company}`) تضمّ عقودها مختصرة. طلب المكتب في الإدارة يقبل `company_id` و`payer` عند الإنشاء.

### بوابة المزوّد (قراءة فقط)
- `GET provider/office-leases` — عقود كل شركاته (تصفية `company_id`، `status`).
- `GET provider/companies/{company}/office-leases` — `authorizeCompany()` (404 لغير شركاته).
- `GET provider/office-leases/{lease}` — 404 إن لم يكن العقد لإحدى شركاته.
- المعروض: المكتب، الفرع، المدة، السعر، الفترات، والفواتير (بنفس `InvoiceResource` بلا `staffFields`).

### تطبيق الأعضاء
- `GET member/companies/{company}/office-leases` — لمن له `is_admin` على الشركة (`CompanyPolicy`)، وإلا 403.
- المعروض: المرجع، المكتب، فرعه، `starts_on`، `ends_on`/`terminated_on`، الحالة. **لا أسعار، ولا فترات، ولا فواتير** — `Resource` منفصل لا يحتوي هذه الحقول أصلاً.

## البنية
- نطاق جديد `App\Domain\Leasing`: `Models/{OfficeLease,OfficeLeasePeriod}`، `Enums/{OfficeLeaseStatus,LeasePeriodStatus}`، `Services/{OfficeLeaseService,LeaseScheduleBuilder,LeaseDailyRunner}`، `Data/LeaseTerms`، `Exceptions/LeasingException`.
- `LeaseScheduleBuilder` بلا قاعدة بيانات: يأخذ (البداية، المدة، الفترة، المبلغ الشهري) ويعيد قائمة الفترات — يُختبر وحده.
- `Leasing` يستورد `Identity` و`Foundation` و`Invoicing` و`Access` و`Providers`؛ لا أحد منها يستورد `Leasing` إلا عبر أعمدة FK (`invoices.office_lease_period_id`، `access_grants.office_lease_id`).
- `docs/decisions/office-leases.md` سجل القرار، وتحديث CLAUDE.md (فقرة قصيرة) وPostman.

## الاختبارات
- **`LeaseScheduleBuilder`:** شهري/ربعي/نصفي/دفعة واحدة؛ بداية يوم 31 و29 شباط؛ آخر فترة تنتهي في `ends_on`؛ مجموع المبالغ = الشهري × المدة.
- **الإنشاء:** شركة جديدة مع عقدها وفاتورتها الأولى وفرعها من المكتب؛ مكتب إضافي لشركة موجودة في فرع آخر؛ كل حالة رفض أعلاه؛ المعاملة تتراجع كاملة عند فشل الفاتورة.
- **التداخل:** عقدان متداخلان لنفس المكتب ← الثاني 409؛ عقد بعد انتهاء/إنهاء السابق مقبول.
- **المهمة اليومية:** تشغيلان متتاليان ← فاتورة واحدة لكل فترة؛ فترات فائتة تصدر كلها؛ فتح الباب يوم البداية؛ `ended` وإلغاء الرمز بعد النهاية؛ فشل TTLock (mock) لا يوقف عقوداً أخرى ويُعاد غداً.
- **الإنهاء:** بتاريخ اليوم (رمز يُلغى فوراً، فترات لاحقة `cancelled`)؛ بتاريخ مستقبلي (فترة تبدأ قبله ما زالت تصدر)؛ عقد منتهٍ ← رفض؛ بلا صلاحية ← 403.
- **الدخول:** رمز العقد يصدر `activated` ولا يُسقطه `expireOverdue()`؛ عضو `door_access_enabled` يفتح، وآخر بلا الخيار لا؛ مكتب بلا قفل ← عقد صحيح مع `access_warning`.
- **العزل:** مزوّد لا يرى عقد شركة غيره (404) ولا يطلب مكتباً لشركة ليست له (404)؛ مدير الشركة لا يرى أسعاراً؛ عضو غير مدير ← 403.
- **الفواتير:** فاتورة إيجار بلا نقاط تقبل دفعة جزئية وإبطالاً وإلغاءً كما في المرحلة 2؛ إلغاؤها لا يمسّ الفترة؛ فاتورة شركة مباشرة `provider_id = null`.
- **المكاتب المتاحة:** مكتب مؤجَّر لا يظهر في التصفح خلال مدة وصوله ويعود بعدها.

## خارج هذا التصميم
- التجديد التلقائي (المرحلة 4)، والتقارير (المرحلة 5).
- التأمين، وغرامة الإنهاء المبكر (تُصدر يدوياً إن لزم في مرحلة لاحقة)، وتعديل سعر أو مدة عقد قائم، ونقل عقد بين مكاتب.
- إيقاف الباب آلياً عند التأخر عن الدفع.
- ظهور الفواتير في تطبيق الأعضاء.
