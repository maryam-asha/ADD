# تصميم: تقارير المزوّدين والإدارة (المرحلة 5)

**الحالة:** معتمد ومنفّذ. **المالكة:** Maryam Asha.
**يبني على:** المراحل 1–4 (`docs/decisions/service-providers.md`، `office-leases.md`، `auto-renewal.md`).

## النطاق (قرار المستخدمة: الخيار ب)
تقارير المال والشركات الآن؛ تقرير الاستخدام (إشغال المقاعد وساعات الحضور) مؤجَّل لمرحلة لاحقة.

## قواعد مشتركة
- **كل المبالغ لكل عملة على حدة** — لا تحويل ولا جمع بين عملات (قاعدة `provider/summary`).
- **الفترة** اختيارية: `from` و`to` (Y-m-d، شاملة) على `invoices.issued_at`؛ بلا فترة = كل الفواتير.
- **الفواتير الملغاة** خارج كل المجاميع.
- **"متأخرة"** = مفتوحة (`unpaid`/`partially_paid`) و`due_at < اليوم` (`Invoice::scopeOverdue()`).
- **أعمار الديون** على المتبقي من الفواتير المتأخرة حسب أيام التأخر عن `due_at`: `0_30` (1–30)، `31_60`، `60_plus` (61+).
- JSON فقط؛ المال نصوص بخانتين (bcmath / SUM في قاعدة البيانات).

## للمزوّد (بوابته؛ شركاته فقط — `companies.provider_id` الحالي)
1. **`GET provider/reports/companies`** — سطر لكل شركة: `id`، `legal_name`، `status`، `members_count`، `membership` (الخطة، المقاعد، الانتهاء، `auto_renew`، `renewal_stop_reason` + نص) أو null، `active_leases_count`، `outstanding[]` (لكل عملة: `remaining`، `overdue`) — فواتير الشركة غير الملغاة لهذا المزوّد.
2. **`GET provider/reports/invoices?from&to`** — فواتير المزوّد (`invoices.provider_id`):
   - `totals[]` لكل عملة: `billed`، `paid`، `remaining`، `overdue`، و`by_payer` (`provider` / `company` بنفس الحقول).
   - `aging[]` لكل عملة: `0_30`، `31_60`، `60_plus`.
   - `by_company[]`: لكل شركة `company` + أسطر لكل عملة (`billed`، `paid`، `remaining`، `overdue`).

## للإدارة (`reports.view`)
3. **`GET admin/reports/providers`** — سطر لكل مزوّد: `id`، `name`، `status`، `active_companies`، `seats` (مجموع مقاعد الاشتراكات السارية لشركاته)، `branch_share[]` (لكل فرع: `branch_id`، `seats`، `capacity` = مقاعد العمل المشترك المفتوحة الآن من `CoworkingPoolService`، `share` = seats/capacity نسبة بخانتين أو null إن كانت السعة 0)، `active_leases`، `outstanding[]` (لكل عملة: `remaining`، `overdue`).
4. **`GET admin/reports/revenue?from&to&provider_id`** — كل الفواتير (مزوّد أو مباشرة):
   - `totals[]` لكل عملة: `billed`، `paid`، `remaining`.
   - `by_source[]` لكل عملة ومصدر: `plan_assignment`، `wallet_top_up` (من `provider_requests.type`)، `office_lease` (`office_lease_period_id`)، `membership_renewal` (`membership_id`).
   - `aging[]` لكل عملة.
   - `top_overdue[]`: أكثر 10 دافعين متأخرين (الدافع = المزوّد إن `billed_to=provider`، وإلا الشركة)، لكل منهم العملة والمتأخر.

## الصلاحيات
`reports.view` (قائمة يدوية في `PermissionSyncService`)؛ مسارا الإدارة في كتلة `withoutMiddleware('role:admin|operations')` مع `permission:reports.view`. تقارير المزوّد في `provider.php` (عزل البوابة القائم).

## الاختبارات
أرقام كل تقرير على بيانات معروفة (عملتان، جزئي، ملغاة، متأخرة في كل فئة)؛ المزوّد لا يرى غيره؛ 403 بلا `reports.view`؛ نسبة الفرع صحيحة؛ الفترة تستبعد ما خارجها.

## خارج النطاق
تقرير الاستخدام وساعات الحضور؛ قائمة الموافقات الموحّدة؛ التصدير لملفات.
