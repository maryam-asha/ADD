# تصميم: مزوّدو الخدمة (المرحلة 1 — النواة والموافقات)

**الحالة:** تصميم معتمد بالمحادثة 2026-10-08، بانتظار مراجعة هذا الملف. **المالكة:** Maryam Asha.
الكود والتعليقات والتوثيق في المستودع بالإنجليزية، والتواصل مع المستخدمة بالعربية.

## السياق

**مزوّد الخدمة** جهة وسيطة (مثال: «بلاتفورم») تتعامل مع شركات خارجية وتحجز لها مقاعد في ADD. المزوّد **ليس مستأجراً**: لا مقاعد له ولا موظفون عندنا. عمله:

1. يطلب مكاتب لشركة عميلة.
2. يسند لها باقة — موجودة، أو باقة يصمّمها بشروطه — فتُحجز المقاعد لموظفيها.
3. يضيف موظفيها ويعيّن مشرفها.
4. يرى تقارير عن شركاته (المرحلة 4).

**الإدارة هي الحَكَم في كل ما يمنح وصولاً أو مقاعد أو يحرّك مالاً.**

لا شيء من هذا موجود اليوم: `companies` مسطّحة بلا علاقة بين شركة وشركة، وإنشاء الشركات محصور بالتشغيل، والخطط الخاصة (`is_public = false`) يسندها التشغيل فقط (`docs/decisions/plan-access-windows.md`).

## قرارات المستخدمة

| # | القرار |
|---|---|
| 1 | المزوّد كيان مستقل (جدول `providers`)، لا شركة بنوع ولا دور فقط — لأن الشركات والمحفظة والتقارير تُجمَّع حوله. |
| 2 | يعمل المزوّد من لوحة ADDOS بحساب Fortify (إيميل + كلمة سر + 2FA). |
| 3 | الإسناد إمّا لباقة موجودة، أو لباقة يصمّمها المزوّد وتمرّ على الموافقة أولاً. |
| 4 | المزوّد يعيّن مشرفاً للشركة العميلة، والمشرف يدير موظفيه بالأدوات الموجودة (الأبواب، توزيع الرصيد). |
| 5 | الموافقة مطلوبة على: الشركة، الموظف، المشرف، تصميم الباقة، إسناد الباقة (حتى الموجودة)، شحن المحفظة. لا موافقة على: الأبواب، توزيع الرصيد، الإزالة. **المبدأ:** ما يمنح يمرّ على الإدارة، وما يسحب أو يدير ضمن الممنوح مباشر. |
| 6 | الموافقات تخصّ **شركات المزوّدين فقط**؛ الشركات المباشرة لا يتغيّر سلوكها. |
| 7 | الموافقة صلاحية (`permission:`) قابلة للإسناد للتشغيل أو لأي دور، لا محصورة بالأدمن. |
| 8 | الحساب يعمل لمزوّد واحد فقط، وكل موظفي المزوّد بصلاحيات متساوية؛ الأدمن ينشئ حساباتهم. |
| 9 | طلب الشركة الجديدة يستعمل مسار المكاتب الخاصة الموجود (`requested → quoted → contracted`). |
| 10 | إيقاف المزوّد يقطع حساباته ويلغي طلباته المعلّقة، وشركاته تكمل؛ والأدمن ينقلها لمزوّد آخر أو لـ ADD مباشرة. |
| 11 | الدفع في مسار المزوّدين **فواتير لا رصيد**، والدافع يُختار: المزوّد أو الشركة (المرحلة 2). |
| 12 | محفظة الشركة العميلة تبقى وعاءً للتوزيع على الموظفين، وتُشحن بطلب موافَق عليه (فاتورته في المرحلة 2). |

## تقسيم العمل

| # | المرحلة | التبعية |
|---|---|---|
| **1** | النواة: المزوّدون، الحسابات، الموافقات، الإيقاف والنقل — **هذا الملف** | — |
| 2 | الفواتير | 1 |
| 3 | التجديد التلقائي | 2 |
| 4 | التقارير | 1–3 |

**تنبيه نشر:** المرحلة 1 وحدها تفعّل الباقات عند الموافقة **بلا فاتورة**. لا تُنشر للإنتاج دون المرحلة 2، أو يُقبل صراحةً أن التحصيل يُسجَّل خارج النظام مؤقتاً.

## نموذج البيانات

### جداول جديدة

**`providers`**
| عمود | النوع | ملاحظة |
|---|---|---|
| `id` | | |
| `name` | string | اسم تجاري، لا ترجمة |
| `contract_ref` | string | عقد المزوّد مع ADD |
| `status` | string + enum `ProviderStatus` | `active` \| `inactive` |
| `timestamps` | | |

**`provider_user`**
| عمود | ملاحظة |
|---|---|
| `id` | |
| `provider_id` | FK, cascade |
| `user_id` | FK, **unique** — الحساب لمزوّد واحد |
| `timestamps` | |

**`provider_requests`** — صندوق الموافقات
| عمود | ملاحظة |
|---|---|
| `provider_id` | FK |
| `submitted_by` | FK users — موظف المزوّد، أو مشرف الشركة العميلة لطلب `company_admin` |
| `type` | enum `ProviderRequestType`: `company_member` · `company_admin` · `plan_design` · `plan_assignment` · `wallet_top_up` |
| `company_id` | FK nullable — null لـ `plan_design` |
| `payload` | json — حسب النوع (أدناه) |
| `status` | enum `ProviderRequestStatus`: `pending` · `approved` · `rejected` · `cancelled` |
| `reviewed_by` · `reviewed_at` | nullable |
| `rejection_reason` | text nullable — إلزامي عند الرفض |
| `result_type` · `result_id` | morph nullable — ما أنتجته الموافقة |
| `timestamps` | |

**`payload` لكل نوع:**
| النوع | الحقول |
|---|---|
| `company_member` | `phone`, `name`, `door_access_enabled` |
| `company_admin` | `user_id` (عضو حالي في الشركة) |
| `plan_design` | حقول `StorePlanRequest` نفسها (الاسم، المدة، السعر بالنقاط، النافذة، الأرصدة، الميزات، المنح…) |
| `plan_assignment` | `plan_id`, `branch_id`, `seats`, `payer` (`provider` \| `company`) |
| `wallet_top_up` | `points`, `payer` |

`payer` يُحفظ في المرحلة 1 ولا يُستعمل حتى المرحلة 2.

**`company_invitations`** — موظف وافق عليه الأدمن ولا حساب له بعد
| عمود | ملاحظة |
|---|---|
| `company_id` | FK |
| `phone` | `09XXXXXXXX` |
| `door_access_enabled` | bool |
| `provider_request_id` | FK — أصل الدعوة |
| `accepted_user_id` · `accepted_at` | nullable |
| unique(`company_id`, `phone`) | |

### أعمدة على جداول موجودة
- `companies.provider_id` — FK nullable, `nullOnDelete`. `null` = شركة مباشرة.
- `private_office_requests.provider_id` · `submitted_by` — nullable. `CompanyController::store` ينقل `provider_id` من الطلب إلى الشركة.
- `plans.provider_id` — nullable. مصمّم الباقة؛ تُنشأ بـ `is_public = false`.

### الربط الشكلي (morph map)
`provider_request` و`provider` يُضافان إلى `MorphMapServiceProvider` (activity log و`result_*`).

## الدخول والصلاحيات

### الدور `provider`
- يُبذر في `RoleSeeder` ويُضاف إلى `ProtectedRoles`.
- `RoleController::update()` يرفض صلاحيات لوحة الإدارة لدور `provider` بـ 422، كما يفعل لـ `member`.
- **لا يجتمع مع أي دور آخر** على حساب واحد: إسناد `provider` لحساب يحمل دوراً آخر، أو العكس، يُرفض بـ 422 في `UserController`.
- ربط الحساب بالمزوّد من `admin/providers/{provider}/users` (إضافة/إزالة)، ويشترط أن يحمل الحساب دور `provider`.

### مجموعة المسارات
في `routes/api.php` مجموعة جديدة تتطلب `routes/api/v1/provider.php` بالبادئة `provider`:

```
auth:sanctum · abilities:dashboard · role:provider · provider.active
```

`provider.active` (`EnsureActiveProvider`) يتأكد أن الحساب مربوط بمزوّد حالته `active`، وإلا 403 برسالة `api.provider.inactive`، ويضع المزوّد على الطلب (`$request->attributes`) لتقرأه الـ controllers.

### العزل — `ProviderPolicy`
- `manageCompany(User, Company)`: `company.provider_id` يساوي مزوّد المستخدم.
- كل مسار في `provider.php` فيه `{company}` يمرّ عليها، والفشل **404** لا 403 حتى لا يُكشف وجود الشركة.
- `RbacStaysFlatTest` يُعدَّل ليقبل `ProviderPolicy` بالاسم مع إحالة إلى `docs/decisions/service-providers.md`؛ يبقى منع أي Policy ثالثة ومنع `scope_type`/`scope_id`.

### صلاحيات جهة الإدارة
مسارات المزوّدين في `admin.php` تخرج من `role:admin|operations` بـ `withoutMiddleware` وتأخذ `permission:` لكل مسار، على نمط Branches (`docs/decisions/rbac-permission-pilot.md`):

| الصلاحية | المسار |
|---|---|
| `providers.view` | قائمة المزوّدين وتفاصيلهم |
| `providers.create` · `providers.update` | الإنشاء والتعديل وربط الحسابات |
| `providers.status` | الإيقاف وإعادة التفعيل |
| `providers.transfer_company` | نقل شركة بين المزوّدين |
| `provider_requests.view` | قائمة الطلبات |
| `provider_requests.approve` · `provider_requests.reject` | القرار |

الـ controllers تُضاف إلى القائمة اليدوية في `PermissionSyncService`، فيأخذها `admin` في كل `permissions:sync`. **قبل أول استعمال يجب تشغيل `permissions:sync`.**

## المسارات

### المزوّد — `provider.php`
| المسار | الوظيفة |
|---|---|
| `GET companies` | شركاته مع عدد الموظفين والاشتراك الحالي |
| `GET companies/{company}` | التفاصيل: الموظفون، المشرفون، الاشتراك، الدعوات المعلّقة |
| `DELETE companies/{company}/members/{user}` | إزالة موظف — مباشرة بلا موافقة |
| `POST office-requests` · `GET office-requests` | طلب شركة جديدة (`PrivateOfficeRequest` مع `provider_id` و`submitted_by`) |
| `GET plans` | ما يستطيع إسناده: العامة الفعّالة + الخاصة التي صمّمها ووُوفق عليها |
| `POST requests` | طلب بأحد الأنواع الخمسة |
| `GET requests` · `GET requests/{providerRequest}` | طلباته، بفلترة الحالة والنوع والشركة |
| `PATCH requests/{providerRequest}/cancel` | إلغاء طلب `pending` |

### مشرف الشركة العميلة — `member.php`
`PATCH companies/{company}/members/{user}/admin` الموجود: إن كانت الشركة لها `provider_id` **لا يغيّر شيئاً**، بل ينشئ طلب `company_admin` ويرجع 202 برسالة `api.member.company_admin_requested`. للشركة المباشرة يبقى كما هو.

### الإدارة — `admin.php`
| المسار | الصلاحية |
|---|---|
| `GET/POST providers` · `GET/PATCH providers/{provider}` | view / create / update |
| `POST/DELETE providers/{provider}/users/{user}` | update |
| `PATCH providers/{provider}/status` | status |
| `PATCH companies/{company}/provider` | transfer_company |
| `GET provider-requests` · `GET provider-requests/{providerRequest}` | provider_requests.view |
| `POST provider-requests/{providerRequest}/approve` | approve |
| `POST provider-requests/{providerRequest}/reject` | reject |

`GET private-office-requests` الموجود يعرض `provider` على كل طلب ويقبل فلتر `provider_id`.

كل استجابة تحديث ترجع `message` مترجمة (`lang/{en,ar}/api.php`، مجموعة `provider` ومفاتيح في `admin`).

## مسار الطلب

### التقديم
1. Form Request لكل نوع يتحقق كاملاً لحظة التقديم:
   - `company_id` شركة المزوّد (`ProviderPolicy`) وحالتها `active`.
   - `plan_assignment`: الباقة فعّالة، وإمّا عامة أو `plan.provider_id` = المزوّد؛ الفرع والمقاعد صالحة.
   - `plan_design`: قواعد `StorePlanRequest` نفسها (تُستخرج إلى trait/class مشتركة بدل نسخها).
   - `company_member`: `SyrianPhoneNumber`؛ لا طلب `pending` ولا دعوة مفتوحة ولا عضوية قائمة بالرقم نفسه في الشركة. **الرسالة لا تكشف وجود حساب بالرقم.**
   - `company_admin`: `user_id` عضو في الشركة وليس مشرفاً.
2. لا طلبين `pending` متطابقين (نفس النوع والشركة والمفتاح).

### الموافقة — `ProviderRequestService::approve(request, reviewer, overrides)`
داخل transaction مع `lockForUpdate` على الطلب:

1. الطلب يجب أن يكون `pending` (موافقة مكرّرة → 409، لا نتيجة ثانية).
2. المزوّد `active` والشركة `active` و`company.provider_id` لم يتغيّر.
3. المعالج المسجَّل للنوع (`ProviderRequestHandler` لكل نوع، مسجّلة في خريطة كما `FULFILLERS`) يعيد الفحص وينفّذ:

| النوع | المعالج يفعل |
|---|---|
| `plan_design` | ينشئ `Plan` بأرصدتها وميزاتها، `is_public = false`, `provider_id` |
| `plan_assignment` | `MembershipActivationService` بلا خصم، المالك الشركة، `branch_id`/`seats` من الطلب؛ تجاوز المخزون متاح **للمراجع فقط** عبر `overrides.oversell` ويُسجَّل |
| `wallet_top_up` | `WalletService::creditGeneral()` لمحفظة الشركة، `performed_by_user_id` = المراجع |
| `company_member` | حساب عضو بالرقم → يُربط بالشركة؛ وإلا → `company_invitations` |
| `company_admin` | `is_admin = true` على `company_user` |

4. `status = approved`، `reviewed_*`، `result_*`.
5. `activity_log` + إشعار لموظفي المزوّد (`ProviderRequestReviewed`).

فشل الفحص عند الموافقة → 422 برسالة تسمّي السبب، والطلب يبقى `pending`.

### الرفض
`rejection_reason` إلزامي؛ `status = rejected`؛ سجل + إشعار.

### الإلغاء
من المزوّد على `pending` فقط → `cancelled`.

### الدعوة المعلّقة
في `register/verify` (داخل transaction إنشاء الحساب نفسها): كل `company_invitations` مفتوحة بالرقم تُربط بشركاتها وتُختم `accepted_*`. الرقم موثَّق بـ OTP، فلا انتحال.

## الإيقاف والنقل

**`PATCH providers/{provider}/status` إلى `inactive`** في transaction واحدة:
- كل طلبات المزوّد `pending` → `cancelled` (`rejection_reason` نظامي: `provider_deactivated`).
- لا مساس بالشركات أو اشتراكاتها أو مشرفيها.
- حساباته تُرفض تلقائياً عبر `provider.active`.

إعادة التفعيل لا تعيد الطلبات الملغاة.

**`PATCH companies/{company}/provider`** بـ `provider_id` أو `null`:
- طلبات المزوّد القديم `pending` على هذه الشركة → `cancelled`.
- `null` تجعلها شركة مباشرة: يعود `updateAdmin` للعمل مباشرة.
- سجل في `activity_log` بالقيمة قبل وبعد.

## الحماية والاختبار

### Guards
- `ProviderRoutesStayIsolatedTest`: لا مسار في `provider.php` يشير إلى controller من `Admin\` أو `Member\`؛ كل controller مزوّد فيه `{company}` يستدعي `ProviderPolicy`.
- `RbacStaysFlatTest` معدَّل (انظر أعلاه).

### Feature
- **العزل:** شركة مزوّد آخر أو شركة مباشرة → 404؛ حساب `provider` على `admin.php`/`member.php` → 403؛ مزوّد موقوف → 403 على كل مساراته؛ token تطبيق الأعضاء (`member-app`) على `provider.php` → 403.
- **كل نوع:** التقديم بفحوصه، الموافقة بنتيجتها، الرفض بسببه، الإلغاء.
- **التزامن:** امتلاء المخزون بين التقديم والموافقة → 422 والطلب `pending`؛ موافقة مكرّرة → اشتراك واحد.
- **الدعوة:** رقم بلا حساب → موافقة → تسجيل بالرقم → عضو في الشركة.
- **regression الشركات المباشرة:** `updateAdmin` يغيّر مباشرة كما كان.
- **الإيقاف والنقل:** إلغاء المعلّق، بقاء الاشتراكات، النقل إلى `null`.
- **الصلاحيات:** دور بلا `provider_requests.approve` → 403؛ معها → 200.
- **الأدوار:** `provider` مع دور آخر → 422؛ صلاحيات إدارة لدور `provider` → 422.

### التوثيق
- `docs/decisions/service-providers.md`: يعدّل القرار «الشركات تُنشأ من التشغيل فقط» إلى «من التشغيل، أو بطلب مزوّد يقرّه التشغيل عبر المسار نفسه»، ويسجّل `ProviderPolicy` استثناءً ثانياً على D.8 مع السبب.
- `CLAUDE.md`: فقرة «Service providers» وسطر `provider.php` في **Route composition**.
- Postman: مجلد Provider ومسارات الإدارة الجديدة.

## خارج المرحلة 1 — قرارات معتمدة للمراحل التالية

### المرحلة 2: الفواتير
- **لمسار المزوّدين فقط**؛ الأعضاء والشركات المباشرة على النقاط كما هم.
- الفاتورة تصدر **عند الموافقة** على `plan_assignment` و`wallet_top_up`، للدافع المختار (`provider` أو `company`).
- **الباقة تتفعّل فوراً**؛ الفاتورة ذمّة بتاريخ استحقاق.
- المبلغ: النقاط محوّلة إلى عملة يختارها المراجع بسعر صرف يوم الإصدار، **مجمَّد**؛ للمراجع تعديله عند الموافقة مع تسجيل.
- السداد يُسجَّل يدوياً (المبلغ، التاريخ، الطريقة، رقم الإيصال)، **جزئي مسموح**؛ الحالة: غير مسدّدة ← مسدّدة جزئياً ← مسدّدة، والمتأخرة تُعرف من الاستحقاق.
- مفتوح: هل للمزوّد خصم تعاقدي ثابت — مؤجَّل حتى يتكرّر.

### المرحلة 3: التجديد التلقائي
- اشتراكات شركات المزوّدين **تتجدّد تلقائياً** مع فاتورة جديدة لكل دورة.
- **يتوقف التجديد** إن كانت على الدافع فاتورة متأخرة، ويُحال للإدارة.
- أول تجديد تلقائي في النظام، محصور بهذه الاشتراكات.

### المرحلة 4: التقارير
endpoints JSON الآن، والتصدير لملفات لاحقاً.
- **المزوّد:** الشركات (الحالة، الموظفون، المقاعد، الباقة والانتهاء)؛ الاستخدام (إشغال المقاعد وساعات الحضور لفترة)؛ الفواتير (مصدرة، مسدّدة، متبقٍّ، متأخرة)؛ طلباته وأسباب الرفض.
- **الإدارة:** المزوّدون (الشركات، المقاعد، النسبة من مخزون كل فرع)؛ الإيرادات (مفوتر، محصّل، ذمم، أعمار الديون 0–30/31–60/60+)؛ قائمة الموافقات الموحّدة؛ التجديدات الموقوفة.
