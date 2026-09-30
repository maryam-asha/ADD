# الدفع بالبطاقة — دليل تطبيق الموبايل

مرجع مطوّر التطبيق لتنفيذ الدفع بالبطاقة عبر Paymera eGate، ولدفع أي عملية
(حجز، جلسة، فعالية، باقة) بالمحفظة أو في الاستقبال — انظر القسم 11.

> **الخلاصة في ثلاث خطوات:** اطلب رابط دفع من الـAPI ← افتحه في WebView ←
> اسأل الـAPI عن النتيجة. التطبيق **لا يتصل بـPaymera إطلاقاً**، ولا يرى
> مفتاح الـAPI، ولا يقرر بنفسه أن الدفع نجح.

رقم البطاقة ورمز الـOTP (5 أرقام، 3 محاولات) يُدخلان في صفحة Paymera داخل
الـWebView — لا يمران عبر التطبيق ولا عبر خادمنا.

---

## 1. المصادقة

كل مسارات الأعضاء تستخدم توكن العضو المعتاد:

```http
Authorization: Bearer <access_token>
Accept: application/json
lang: ar
```

هو نفسه التوكن الصادر عن `POST /api/v1/auth/login`. لا يوجد توكن خاص بالدفع.

---

## 2. بدء عملية دفع

```http
POST /api/v1/member/payments
```

### أ. شحن المحفظة

```json
{
  "purpose": "wallet_top_up",
  "amount": "1470000",
  "lang": "ar"
}
```

`amount` بالوحدات الكبرى للعملة المشحونة (ليرة سورية) — أي `1470000` تعني
1,470,000 ل.س. **لا ترسل قروشاً ولا فواصل ولا رموز عملة.**

### ب. شراء باقة

```json
{
  "purpose": "plan_purchase",
  "plan_id": 7,
  "lang": "ar"
}
```

**لا ترسل `amount` هنا.** السعر يُقرأ من قاعدة البيانات، وأي `amount` مرسل
يُتجاهل تماماً. هذا مقصود: العميل لا يحدد ما سيُخصم من بطاقته.

### الحقول

| الحقل | إلزامي | الوصف |
|---|---|---|
| `purpose` | نعم | `wallet_top_up` أو `plan_purchase` |
| `amount` | مع `wallet_top_up` فقط | نص/رقم، بالوحدات الكبرى، ≥ 0.01 |
| `plan_id` | مع `plan_purchase` فقط | معرّف باقة متاحة للبيع |
| `lang` | لا | `ar` أو `en` — يحدد لغة صفحة Paymera |

### الرد الناجح — 201

```json
{
  "data": {
    "payment": {
      "id": 12,
      "status": "pending",
      "purpose": "wallet_top_up",
      "amount_minor": 147000000,
      "currency": "SYP",
      "currency_exponent": 2,
      "points_to_credit": 1000,
      "plan_id": null,
      "membership_id": null,
      "rrn": null,
      "created_at": "2026-09-27T12:48:07.000000Z"
    },
    "redirect_url": "https://egate-t.paymera.cc/start/{paymentId}/ar"
  }
}
```

احفظ `data.payment.id` — ستحتاجه للاستعلام — وافتح `data.redirect_url`.

### الأخطاء

| الرمز | المعنى | ما يُعرض للمستخدم |
|---|---|---|
| `422` | خطأ تحقق (`errors` يحوي التفاصيل) | رسالة الحقل |
| `503` | البوابة غير متاحة (إعداد لدينا) | "الدفع بالبطاقة غير متاح مؤقتاً" |
| `502` | تعذّر بدء العملية | "تعذّر بدء الدفع، حاول ثانية" |

**503 و502 ليسا رفضاً للبطاقة** — لم تُشحن البطاقة أصلاً، والخلل عندنا.
لا تعرضهما كـ"فشل الدفع".

---

## 3. تدفق الـWebView

1. افتح `redirect_url` في WebView.
2. راقب التنقل: عندما يصبح الرابط محتوياً على **`/api/v1/paymera/callback/`**
   فقد انتهى المستخدم (سواء بالدفع أو بالإلغاء).
3. أغلق الـWebView.
4. انتقل إلى الاستعلام عن الحالة (القسم 4).

```
افتح redirect_url في WebView
        │
   المستخدم يدخل البطاقة + OTP على صفحة Paymera
        │
   الرابط يحوي /api/v1/paymera/callback/  ←── إشارة الانتهاء
        │
   أغلق الـWebView ثم استعلم عن الحالة
```

> **مهم:** الوصول إلى صفحة الـcallback **لا يعني النجاح**. المستخدم يصلها
> أيضاً بالضغط على Cancel. المصدر الوحيد للنتيجة هو استعلام الحالة.

متطلبات الـWebView: تفعيل JavaScript والـcookies، والسماح بالتوجيه
(redirects). لا تعترض أي رابط آخر ولا تحقن أي سكربت في صفحة البنك.

---

## 4. الاستعلام عن الحالة

```http
GET /api/v1/member/payments/{id}
```

```json
{
  "data": {
    "id": 12,
    "status": "accepted",
    "purpose": "wallet_top_up",
    "amount_minor": 147000000,
    "currency": "SYP",
    "currency_exponent": 2,
    "points_to_credit": 1000,
    "plan_id": null,
    "membership_id": null,
    "rrn": "000053127772",
    "created_at": "2026-09-27T12:48:07.000000Z"
  }
}
```

> **انتبه للفرق:** هنا البيانات في `data` مباشرة، بينما في رد الإنشاء (201)
> كانت داخل `data.payment`. هذا مصدر خطأ شائع.

الخادم يسأل البوابة عند كل استعلام، والعملية محمية بقفل وغير قابلة للتكرار،
**فالاستعلام المتكرر آمن ولا يشحن النقاط مرتين.**

### سياسة الاستعلام المقترحة

استعلم بعد إغلاق الـWebView مباشرة، ثم أعد المحاولة بتباعد متزايد
(1s، 2s، 4s، 8s) لنحو 30 ثانية. إن بقيت `pending` فاعرض "سنؤكد العملية
خلال دقائق" — مهمة مجدولة تسوّي كل دفعة معلّقة خلال 5 دقائق على أي حال،
فلا حاجة لإبقاء المستخدم منتظراً.

---

## 5. حالات `status` وكيف تُعرض

| `status` | المعنى | ما يُعرض |
|---|---|---|
| `pending` | لم تُحسم بعد | "جارٍ التحقق…" وتابع الاستعلام |
| `accepted` | نجحت، والنقاط أُضيفت | نجاح ✅ |
| `failed` | رفض البنك أو خطأ OTP | فشل — لم يُخصم شيء |
| `canceled` | ألغى المستخدم | إلغاء — لم يُخصم شيء |
| `expired` | انتهت مهلتها دون حسم | فشل — اطلب إعادة المحاولة |
| `needs_review` | **ليست فشلاً** | "قيد المراجعة" + تواصل مع الدعم |
| `reversed` | أُلغيت من قبل الإدارة بعد نجاحها | مُستردّة |

### `needs_review` — الحالة التي يجب عدم إساءة عرضها

تعني أن المال أُخذ ويحتاج تدخلاً بشرياً. **لا تعرضها كفشل**، ولا تدعُ
المستخدم لإعادة الدفع — فقد يدفع مرتين.

الحالة الخاصة في شراء الباقات: `status = "needs_review"` مع
**`membership_id = null`** تعني: "أُخذ المال، والنقاط في محفظتك، لكن الباقة
لم تُفعّل". النقاط **لم تضِع**. اعرض ما يناسب ذلك ووجّه المستخدم للدعم.

---

## 6. عرض المبالغ

المبلغ يأتي كعدد صحيح من الوحدات الصغرى مع الأُس:

```
القيمة المعروضة = amount_minor / (10 ^ currency_exponent)
147000000 / 10^2 = 1,470,000.00 ل.س
```

**لا تُحوّل `amount_minor` إلى float ولا تعتمد قسمة عشرية جاهزة.** استخدم
حساباً صحيحاً أو مكتبة decimal، واعرض العملة من `currency`.

`points_to_credit` هو عدد النقاط التي ستُضاف — وهو مثبَّت لحظة إنشاء
الدفعة، فلا يتغير إن تغيّر سعر النقطة أثناء الدفع.

---

## 7. سجلّ عمليات العضو

```http
GET /api/v1/member/payments
```

```json
{
  "data": [ { /* نفس حقول العنصر أعلاه */ } ],
  "meta": { "current_page": 1, "last_page": 3, "total": 47 }
}
```

مرتّب من الأحدث، 20 عنصراً في الصفحة، ويعيد دفعات العضو الحالي فقط.

---

## 8. الحقول المتاحة للعضو

أحد عشر حقلاً فقط:

`id` · `status` · `purpose` · `amount_minor` · `currency` ·
`currency_exponent` · `points_to_credit` · `plan_id` · `membership_id` ·
`rrn` · `created_at`

`rrn` هو الرقم المرجعي البنكي — اعرضه في إيصال النجاح، فهو ما يُستشهد به
عند أي نزاع (يطابق عمود `Trans RRN` في لوحة Paymera).

حقول أخرى (`terminal_id`، `review_reason`، `gateway_payment_id`…) متاحة
للوحة الإدارة فقط ولن تصل التطبيق.

---

## 9. أخطاء شائعة يجب تجنّبها

- ❌ اعتبار وصول الـWebView إلى صفحة الـcallback نجاحاً — استعلم دائماً.
- ❌ قراءة `data.status` في رد الإنشاء — هناك المسار `data.payment.status`.
- ❌ إرسال `amount` مع `plan_purchase` — يُتجاهل.
- ❌ عرض `needs_review` كفشل ودعوة المستخدم للدفع ثانية.
- ❌ تحويل `amount_minor` إلى float.
- ❌ محاولة استدعاء Paymera مباشرة من التطبيق.
- ❌ اعتبار 502/503 رفضاً للبطاقة.

---

## 10. الاختبار

بيئة الاختبار تستخدم `https://egate-t.paymera.cc` وبطاقة تجريبية يزودك بها
فريق الباك-إند. السيناريوهات التي يجب تغطيتها:

| السيناريو | المتوقع |
|---|---|
| دفع ناجح | `accepted` + `rrn` |
| OTP خاطئ 3 مرات | `failed` |
| ضغط Cancel | `canceled` |
| إغلاق الـWebView قبل العودة | `pending` ثم يُحسم خلال 5 دقائق |
| شراء باقة ناجح | `accepted` + `membership_id` |
| انقطاع الشبكة أثناء الاستعلام | إعادة محاولة، لا رسالة فشل |
| العربية والإنجليزية | صفحة البنك ورسائلنا بنفس اللغة |

---

## 11. الدفع لأي عملية — المحفظة، الاستقبال، البطاقة

المسار نفسه `POST /api/v1/member/payments` صار يدفع كل ما يدفعه العضو، لا
الشحن والباقة فقط. الطلب القديم (بلا `method`) يعمل كما هو تماماً، والرد
يضيف حقلاً واحداً: `next`.

### أ. معرفة طرق الدفع قبل الدفع

```http
GET /api/v1/member/payment-methods?purpose=booking&payable_id=15
```

| `purpose` | معه |
|---|---|
| `booking` | `payable_id` = رقم الحجز |
| `coworking_session` | `payable_id` = رقم الجلسة (بعد إغلاقها) |
| `event_registration` | `payable_id` = `ticket_id` من التسجيل |
| `plan_purchase` | `plan_id` |
| `wallet_top_up` | لا شيء (العضو يحدد المبلغ) |

```json
{
  "data": {
    "amount_points": 100,
    "price": "10.00 $",
    "price_point": "100 pts",
    "methods": [
      {
        "key": "wallet", "label": "نقاط المحفظة", "available": true, "reason": null,
        "wallets": [
          {"owner_type": "user", "owner_id": 7, "owner_label": "Personal", "usable_balance": 300, "sufficient": true},
          {"owner_type": "company", "owner_id": 3, "owner_label": "ACME", "usable_balance": 50, "sufficient": false}
        ]
      },
      {"key": "cash", "label": "نقداً في الاستقبال", "available": true, "reason": null},
      {"key": "paymera", "label": "بطاقة", "available": true, "reason": null}
    ]
  }
}
```

- اعرض الطرق **بالترتيب الذي تصل به**، ولا تفترض أسماءها: طريقة جديدة تُضاف
  من الخادم تظهر هنا وحدها.
- `available: false` مع `reason`: اعرض الطريقة معطّلة مع السبب (مثلاً نقاط
  غير كافية).
- الحجز المدفوع مسبقاً ← `409`؛ ما ليس للعضو ← `404`.

### ب. الدفع

```http
POST /api/v1/member/payments
{
  "purpose": "booking",
  "payable_id": 15,
  "method": "wallet",
  "wallet_owner_type": "company",
  "wallet_owner_id": 3
}
```

`method`: `wallet` | `cash` | `paymera` (أو أي مفتاح وصل في القائمة).
`wallet_owner_type/id` اختياريان للمحفظة؛ إذا كان للعضو أكثر من محفظة ولم
يحدد، يعود 422 مع `wallet_options` كما في الحجز.

الرد دائماً 201:

```json
{"data": {"payment": {...}, "redirect_url": "https://...", "next": "redirect"}}
```

| `next` | ماذا يفعل التطبيق |
|---|---|
| `redirect` | يفتح `redirect_url` في WebView ثم يستعلم `GET payments/{id}` كما في القسم 3 و4 |
| `done` | دُفع الآن من المحفظة — حدّث الشاشة |
| `pay_at_reception` | لا شيء يُدفع الآن؛ اعرض "ادفع في الاستقبال". قد يكون `payment` هنا `null` |

نقاط غير كافية ← 422 `{message, current, required, shortage}`.

### ج. الدفع عند الإنشاء

- **الحجز:** `POST member/bookings` يقبل `payment_method`. `wallet` يدفع
  الآن أو يرفض بـ 422 ولا يُنشأ الحجز؛ `cash` ينشئه غير مدفوع؛ `paymera`
  ينشئه غير مدفوع ويضيف بجانب `data` الحقلين `payment` و`redirect_url`. إن
  تعذّر فتح صفحة البطاقة يبقى الحجز، مع `payment: null` و`payment_error`.
  الحجز المتعدد (`/batch`) يقبل `wallet` أو `cash` فقط.
- **الفعالية:** `POST member/events/{id}/register` يقبل `payment_method`
  و`wallet_owner_type/id`. البطاقة أو النقد يؤكدان المقعد **غير مدفوع**، ولا
  يُسمح بالدخول قبل الدفع.
- **الباقة:** `POST member/memberships` يقبل `payment_method`. `cash` يعيد
  `{"data": {"pending_purchase": {...}}}` — حجز لمدة 48 ساعة بلا اشتراك حتى
  يدفع العضو في الاستقبال، ويظهر أيضاً في `memberships/current` تحت
  `pending_purchase`. `paymera` يعيد شكل بدء الدفع أعلاه.

### د. كتلة `payment` في الشاشات

تظهر على الحجوزات (القائمة والتفاصيل)، والجلسة الحالية، وتقرير الجلسة
(`totals.payment`)، وتسجيل الفعالية:

```json
{"state": "unpaid", "method": null, "amount_points": 100, "price": "10.00 $",
 "price_point": "100 pts", "paid_at": null, "payment_id": null}
```

`state`: `paid` | `unpaid` | `pending` (دفع بطاقة جارٍ أو باقة محجوزة للنقد)
| `null` (لا شيء للدفع: فعالية مجانية أو جلسة ما زالت مفتوحة).

في الحجوزات بقي `state` كما كان (`paid`/`unpaid`) حفاظاً على التطبيق الحالي،
والدفع الجاري يظهر في `awaiting_payment: true`.

### هـ. زر "ادفع الآن"

كل ما هو `unpaid` في الكتلة أعلاه يُدفع لاحقاً بالمسار نفسه (ب) باستخدام
`payable_id`. الجلسة المفتوحة لا تُدفع قبل إغلاقها (422).
