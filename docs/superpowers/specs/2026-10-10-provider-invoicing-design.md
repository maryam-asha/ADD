# تصميم: فواتير مزوّدي الخدمة (المرحلة 2)

**الحالة:** تصميم معتمد بالمحادثة 2026-10-10، بانتظار مراجعة هذا الملف. **المالكة:** Maryam Asha.
**يبني على:** `docs/superpowers/specs/2026-10-08-service-providers-design.md` (المرحلة 1، منفّذة) و`docs/decisions/service-providers.md`.
الكود والتعليقات والتوثيق في المستودع بالإنجليزية، والتواصل مع المستخدمة بالعربية.

## السياق

في المرحلة 1 تفعّل الموافقةُ على **إسناد باقة** أو **شحن محفظة** لشركة مزوّد ما طُلب **دون أي تحصيل مسجّل**، ويُحفظ في الطلب فقط مَن سيدفع (`payload.payer`: `provider` | `company`). هذه المرحلة تجعل كل موافقة من هذين النوعين تُصدر **فاتورة**: دَين بالمال على الدافع، يُسدَّد يدوياً على دفعات، وتُعرف المتأخرة من موعدها.

**لماذا ليست في جدول `payments` الحالي:** ذلك الجدول مبني على أن كل مال يتحوّل نقاطاً في محفظة ثم يُصرف (`docs/decisions/unified-payments.md`)، ودرج الصندوق (`CashRegisterService`) يحسب النقد المتوقّع من حركات المحافظ النقدية. الفاتورة دَين بالمال لا بالنقاط؛ خلطها هناك يكسر حسابات النقاط والدرج.

## قرارات المستخدمة

| # | القرار |
|---|---|
| 1 | الفواتير **لمسار المزوّدين فقط**؛ الأعضاء والشركات المباشرة على النقاط كما هم. |
| 2 | تُصدر عند الموافقة على `plan_assignment` و`wallet_top_up`، باسم الدافع المختار في الطلب. |
| 3 | **الباقة تتفعّل فوراً**؛ الفاتورة ذمّة لها آخر موعد للدفع. |
| 4 | المبلغ = النقاط محوّلة إلى عملة يختارها الأدمن (الأساسية إن لم يختر) بسعر صرف يوم الإصدار، **مجمَّد**؛ للأدمن تعديله عند الموافقة مع تسجيل التعديل. |
| 5 | نقاط الإسناد = **سعر الباقة × عدد المقاعد**. نقاط الشحن = النقاط المطلوبة. |
| 6 | آخر موعد للدفع = تاريخ الإصدار + مدة المزوّد، وإن لم تُحدَّد فالمدة العامة من الإعدادات (افتراضياً 15 يوماً)؛ للأدمن تعديله عند الموافقة. |
| 7 | تسجيل الدفعات **من لوحة الإدارة فقط** بصلاحية، **خارج درج الصندوق**. |
| 8 | **الدفع الجزئي مسموح.** دفعة أكبر من المتبقي تُرفض. |
| 9 | الدفعة **بأي عملة**؛ النظام يحوّلها إلى عملة الفاتورة بسعر صرف **يوم الدفع**، ويحفظ المبلغ الأصلي والسعر. |
| 10 | دفعة خاطئة **تُبطَل ولا تُحذف**، بسبب، ولا تُحتسب. الإبطال صلاحية مستقلة. |
| 11 | إلغاء الفاتورة بسبب، **فقط إن لم يكن عليها دفعة فعّالة**؛ تبقى ظاهرة بحالة «ملغاة»، و**الباقة لا تتأثر**. |
| 12 | الفواتير بين المزوّد والإدارة فقط؛ **لا تظهر في تطبيق الأعضاء**. المزوّد يراها للاطلاع ولا يسجّل دفعات. |

## نموذج البيانات

### `invoices`
| عمود | النوع | ملاحظة |
|---|---|---|
| `id` | | |
| `reference` | string unique | `INV-2026-00012` عبر `HasReference`، تسلسل يبدأ كل سنة من `issued_at` |
| `provider_id` | FK **nullable** | مزوّد الشركة وقت الإصدار؛ فارغ لفاتورة شركة مباشرة (مصادر لاحقة) |
| `company_id` | FK | الشركة التي تخصّها الفاتورة دائماً |
| `billed_to` | string + enum `Payer` (الموجود من المرحلة 1) | `provider` \| `company` |
| `provider_request_id` | FK **nullable** unique | **المصدر** الوحيد في هذه المرحلة: طلب واحد ← فاتورة واحدة على الأكثر |
| `description` | string | يكتبه النظام: «Plan: {اسم الباقة} × {seats} seats, {duration_days} days» أو «Wallet top-up: {points} points» |
| `points` | unsignedInteger | قيمة الطلب بالنقاط |
| `currency` | string(3) FK `currencies.code` | |
| `computed_amount` | decimal(14,2) | ما حسبه النظام قبل أي تعديل |
| `amount` | decimal(14,2) | المبلغ المجمَّد المستحق |
| `exchange_rate_to_base` | decimal(18,8) | سعر العملة مقابل الأساسية يوم الإصدار (1 للأساسية) |
| `paid_amount` | decimal(14,2) default 0 | مجموع الدفعات الفعّالة، يُحدَّث داخل القفل |
| `status` | string + enum `InvoiceStatus` | `unpaid` · `partially_paid` · `paid` · `cancelled` |
| `issued_at` | date | |
| `due_at` | date | |
| `issued_by` | FK users | المراجع الذي وافق |
| `cancelled_at` · `cancelled_by` · `cancellation_reason` | nullable | |
| `timestamps` | | |

**مصدر الفاتورة عام** (قرار 2026-10-10): الفاتورة لا تفترض أنها من طلب مزوّد. كل مصدر عمود FK خاص به nullable — اليوم `provider_request_id` وحده، ولاحقاً `office_lease_id` لإيجار المكاتب (المرحلة 3) — ولا morph (المورف ماب غير مفروض، ونتجنّب تغيير ما يُكتب لنماذج موجودة). `InvoiceIssuer` يبني الفاتورة من وصف مستقل عن المصدر (الشركة، الدافع، النقاط، الوصف)، و`issueFor(ProviderRequest …)` غلاف رفيع فوقه، فيضيف مصدر جديد غلافاً آخر دون لمس الدفع والإلغاء والقوائم.

دقّة الأعمدة المالية تتبع ما في جداول المال الحالية (`payments`، `wallet_transactions`)؛ إن اختلفت فالمرجع ما هو موجود.

**«متأخرة» ليست حالة مخزّنة:** `status ∈ {unpaid, partially_paid}` و`due_at < اليوم` — scope `overdue()` وحقل محسوب `is_overdue` في الاستجابة.

### `invoice_payments`
| عمود | ملاحظة |
|---|---|
| `invoice_id` | FK |
| `received_amount` · `received_currency` | كما استلمه الموظف |
| `exchange_rate` | سعر التحويل من عملة الدفعة إلى عملة الفاتورة يوم الدفع |
| `amount` | decimal — المبلغ بعملة الفاتورة، هو ما يُطرح من المتبقي |
| `method` | string + enum `InvoicePaymentMethod`: `bank_transfer` · `cash` · `cheque` · `other` |
| `receipt_number` | string nullable |
| `paid_at` | date |
| `recorded_by` | FK users |
| `voided_at` · `voided_by` · `void_reason` | nullable |
| `timestamps` | |

### أعمدة وإعدادات
- `providers.payment_terms_days` — unsignedSmallInteger nullable.
- إعداد جديد في جدول الإعدادات `invoices.default_due_days` = 15 (عبر `SettingSeeder` وبنمطه).

## المكوّنات

قسم جديد `App\Domain\Invoicing` (Models: `Invoice`, `InvoicePayment`؛ Enums: `InvoiceStatus`, `InvoicePaymentMethod`؛ Services).

### `InvoiceIssuer`
`issueFor(ProviderRequest $request, User $reviewer, array $terms): ?Invoice`
- يعيد `null` لأي نوع غير `plan_assignment`/`wallet_top_up`.
- النقاط: إسناد → `plan.price_points × payload.seats`؛ شحن → `payload.points`.
- المبلغ المحسوب: `PointConversionService::pointsToCurrency($points, $currency)` — المكان الوحيد الذي يقرأ سعر النقطة (`PointConversionIsCentralizedTest`). يعيد null عند غياب سعر الصرف → `InvoicingException('api.invoice.no_exchange_rate')`.
- `$terms`: `currency` (افتراضياً الأساسية)، `amount` (يتجاوز المحسوب)، `due_at` (يتجاوز المحسوب).
- `due_at` الافتراضي = اليوم + (`provider.payment_terms_days` ?? إعداد `invoices.default_due_days`).
- يكتب `activity_log` (`invoice_issued`، ومعه `amount_overridden` و`due_overridden` عند التعديل).

**الربط بالموافقة:** `ProviderRequestService::approve()` يستدعي `InvoiceIssuer::issueFor()` **بعد المعالج وداخل المعاملة نفسها**؛ أي استثناء منه يُرجع كل شيء ويبقي الطلب معلّقاً. `ApproveProviderRequestRequest` يقبل حقولاً اختيارية جديدة: `invoice_currency` (exists في currencies)، `invoice_amount` (decimal > 0)، `invoice_due_at` (date ≥ اليوم). تُمرَّر إلى المُصدِر منفصلة عن `override_capacity`.

**معاينة قبل الموافقة:** `GET admin/provider-requests/{id}` يضيف لطلبات الإسناد والشحن المعلّقة كتلة `invoice_preview`: `points`، `currency` (الأساسية)، `amount`، `due_at` — بالحساب نفسه دون حفظ، وبعد الموافقة كتلة `invoice` (المرجع والحالة) بدلها.

### `InvoicePaymentService`
كل عملية داخل معاملة مع `lockForUpdate` على الفاتورة:
- `record(Invoice, array $data, User): InvoicePayment` — يرفض فاتورة `cancelled`/`paid` (`api.invoice.not_payable`، 409)؛ يحوّل المبلغ المستلم إلى عملة الفاتورة بسعر يوم الدفع (bcmath عبر `CurrencyConversionService::rateToBase()` للعملتين، مقرّباً لأعلى لخانات عملة الفاتورة — اتجاه التقريب نفسه في النظام)؛ عند غياب سعر → `api.invoice.no_exchange_rate`؛ إن تجاوز المتبقي → 422 `api.invoice.overpayment` مع المتبقي؛ يزيد `paid_amount` ويعيد اشتقاق الحالة.
- `void(InvoicePayment, string $reason, User)` — يرفض المُبطلة سلفاً (409)؛ يطرح من `paid_amount` ويعيد اشتقاق الحالة.
- `cancel(Invoice, string $reason, User)` — يرفض إن كان `paid_amount > 0` (`api.invoice.has_payments`، 422) أو كانت ملغاة (409).
- اشتقاق الحالة في مكان واحد (`Invoice::refreshStatus()`): `paid_amount = 0` → `unpaid`؛ `0 < paid_amount < amount` → `partially_paid`؛ `paid_amount ≥ amount` → `paid`؛ الملغاة لا تتغيّر.
- كل عملية تكتب `activity_log`.

## المسارات

### الإدارة — `admin.php` (داخل كتلة الصلاحيات الخاصة بالمزوّدين، بنمط Branches)
| المسار | الصلاحية |
|---|---|
| `GET invoices` | `invoices.view` — فلترة: `status`، `overdue=1`، `provider_id`، `company_id`، `billed_to`؛ مرقّمة |
| `GET invoices/{invoice}` | `invoices.view` — مع الدفعات |
| `POST invoices/{invoice}/payments` | `invoices.record_payment` — يرجع الدفعة (201) |
| `POST invoice-payments/{invoicePayment}/void` | `invoices.void_payment` — `reason` إلزامي |
| `POST invoices/{invoice}/cancel` | `invoices.cancel` — `reason` إلزامي |

الـ controllers تُضاف إلى `PermissionSyncService::MANUAL_REGISTRATIONS`. `UpdateProviderRequest` يقبل `payment_terms_days` (nullable، 1–365) ويعرضه `ProviderResource`.

### المزوّد — `provider.php` (للاطلاع فقط)
| المسار | ملاحظة |
|---|---|
| `GET invoices` | فواتير شركاته (`invoices.provider_id` = مزوّده) بالفلاتر نفسها عدا `provider_id` |
| `GET invoices/{invoice}` | 404 لفاتورة مزوّد آخر — نمط `authorizeCompany` |

الاستجابة لا تُظهر للمزوّد `issued_by`/`recorded_by` (أسماء موظفي ADD) — فقط المبالغ والتواريخ والحالة والطريقة ورقم الإيصال.

## الحماية والاختبار

### Guards
- `ProviderRoutesStayIsolatedTest` يغطي الـ controller الجديد تلقائياً (يأخذ `Invoice`) — يُوسَّع شرطه ليشمل `Invoice $invoice` إلى جانب `Company $company`.
- `MoneyIsDecimalOnlyTest` و`PointConversionIsCentralizedTest` و`EnumColumnsHaveBackedEnumCastsTest` (تُسجَّل الأعمدة الجديدة).

### Feature
- **الإصدار:** إسناد بخمسة مقاعد لباقة 900 نقطة → فاتورة بـ 4500 نقطة، بالعملة الأساسية، `due_at` = اليوم + مدة المزوّد؛ مزوّد بلا مدة → المدة العامة؛ تعديل المبلغ والعملة والموعد يُحفظ مع `computed_amount` الأصلي؛ عملة بلا سعر صرف → 422، الطلب معلّق، لا اشتراك ولا فاتورة؛ موافقة على موظف أو تصميم باقة لا تُصدر فاتورة؛ `billed_to` يطابق `payer`.
- **المعاينة:** `invoice_preview` على طلب معلّق يطابق ما تُصدره الموافقة دون تعديل.
- **الدفع:** جزئية ثم مكمّلة → `partially_paid` ثم `paid`؛ بعملة أخرى تُحوَّل بسعر يومها ويُحفظ الأصل؛ أكبر من المتبقي → 422؛ على ملغاة أو مدفوعة → 409.
- **الإبطال والإلغاء:** إبطال يعيد الحالة؛ إبطال مكرّر → 409؛ إلغاء فاتورة بدفعة فعّالة → 422، وبعد إبطالها ينجح؛ الباقة باقية بعد الإلغاء.
- **المتأخرة:** `overdue=1` يعيد غير المدفوعة كلياً بعد موعدها فقط.
- **العزل والصلاحيات:** مزوّد على فاتورة مزوّد آخر → 404؛ مزوّد لا يرى `recorded_by`؛ مزوّد على مسارات الدفع الإدارية → 403؛ دور بلا `invoices.record_payment` → 403، ومعها 201.

### التوثيق
- `docs/decisions/service-providers.md`: قسم Invoicing، ورفع تحذير «لا نشر قبل المرحلة 2».
- `CLAUDE.md`: سطر في فقرة Service providers عن الفواتير، وأنها خارج دفتر النقاط ودرج الصندوق.
- Postman: مجلدات الفواتير للإدارة والمزوّد، وحقول الموافقة الجديدة.

## ترتيب المراحل (معدّل 2026-10-10)
2 الفواتير (هذا الملف) ← 3 **إيجار المكاتب الخاصة** لكل الشركات (مسار `private-office-requests` ينتهي اليوم بإنشاء الشركة فقط، دون مكتب أو مدة أو سعر أو منع حجز المكتب؛ جلسة تصميم مستقلة) ← 4 التجديد التلقائي (الباقات والإيجارات) ← 5 التقارير.

## خارج هذه المرحلة
- إيجار المكاتب الخاصة — المرحلة 3؛ الفواتير مبنية لتقبله مصدراً.
- التجديد التلقائي وإيقافه عند فاتورة متأخرة — المرحلة 4.
- التقارير (الإيرادات، أعمار الديون) — المرحلة 5؛ هذه البيانات تجهّزها.
- ملف PDF للفاتورة وإرسالها — لاحقاً مع تصدير الملفات.
- تنبيه قبل الموعد أو عند التأخر.
- الضرائب.
- فواتير الشركات المباشرة والأعضاء.
- **ملاحظة مفتوحة خارج النطاق:** الشراء المباشر لشركة (التطبيق، الاستقبال) يخصم سعر الباقة مرة واحدة مهما كان عدد المقاعد، بينما فواتير المزوّدين بسعر × المقاعد. لم يُحسم إن كان المسار المباشر خطأ.
