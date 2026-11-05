<?php

return [

    'booking' => [
        'pending_approval' => [
            'title' => 'حجز بانتظار موافقتك',
            'body' => ':member طلب :space يوم :date الساعة :time.',
        ],
        // Kept for the notices stored before approvals carried a payment
        // line; new ones are approved_with_payment.
        'approved' => [
            'title' => 'تمت الموافقة على حجزك',
            'body' => 'حجزك لـ :space يوم :date الساعة :time مؤكد.',
        ],
        'approved_with_payment' => [
            'title' => 'تمت الموافقة على حجزك',
            'body' => 'حجزك لـ :space يوم :date الساعة :time مؤكد.:payment',
        ],
        'confirmed' => [
            'title' => 'تم تأكيد حجزك',
            'body' => 'حجزك لـ :space يوم :date الساعة :time مؤكد.:payment',
        ],
        'confirmed_batch' => [
            'title' => 'تم تأكيد حجوزاتك',
            'body' => 'تم تأكيد :count حجوزات لـ :space (:dates).:payment',
        ],
        'booked_for_you' => [
            'title' => 'حجز لك فريق ADD',
            'body' => 'حجز لك فريق ADD :space يوم :date الساعة :time.:payment',
        ],
        // The sentence a confirmation ends with (BookingPaymentLine).
        'payment_lines' => [
            'wallet' => 'اقتُطع :points نقطة من محفظتك.',
            'company_wallet' => 'اقتُطع :points نقطة من محفظة :company.',
            'card' => 'دُفع :points نقطة بالبطاقة.',
            'cash_paid' => 'دفعت :points نقطة نقداً في الاستقبال.',
            'cash_due' => 'يُدفع المبلغ (:points نقطة) في الاستقبال.',
            'plan' => 'الحجز مغطّى بالكامل من ساعات باقتك.',
            'due' => 'المبلغ :points نقطة مستحق، ادفعه من التطبيق أو في الاستقبال.',
        ],
        'list_separator' => '، ',
        'rejected' => [
            'title' => 'لم تتم الموافقة على حجزك',
            'body' => 'رُفض حجزك لـ :space يوم :date، وأُعيد :points نقطة إلى محفظتك.',
        ],
        'rejected_no_refund' => [
            'title' => 'لم تتم الموافقة على حجزك',
            'body' => 'رُفض حجزك لـ :space يوم :date.',
        ],
        'lapsed' => [
            'title' => 'لم يُؤكَّد حجزك',
            'body' => 'حلّ موعد حجزك لـ :space قبل الموافقة عليه، وأُعيد المبلغ إلى محفظتك.',
        ],
        'lapsed_no_refund' => [
            'title' => 'لم يُؤكَّد حجزك',
            'body' => 'حلّ موعد حجزك لـ :space قبل الموافقة عليه.',
        ],
        'payment_expired' => [
            'title' => 'أُلغي حجزك لعدم الدفع',
            'body' => 'انتهت مهلة دفع حجزك لـ :space، وأصبح الموعد متاحاً لغيرك.',
        ],
        'no_show' => [
            'title' => 'فاتك حجزك',
            'body' => 'انتهى حجزك لـ :space يوم :date دون تسجيل حضور.',
        ],
        'released_no_show' => [
            'title' => 'أُلغي حجزك',
            'body' => 'لم تصل حتى الساعة :deadline، فأُلغي حجزك غير المدفوع في :space يوم :date وتحرّر المقعد.',
        ],
        'reminder' => [
            'title' => 'حجزك يبدأ قريباً',
            'body' => 'يبدأ حجزك في :space الساعة :time. امسح رمز الكيوسك عند وصولك.',
        ],
        'ending_soon' => [
            'title' => 'حجزك ينتهي بعد :minutes دقائق',
            'body' => 'ينتهي حجزك في :space الساعة :time. يمكنك التمديد من التطبيق إن كانت المساحة متاحة.',
        ],
        'extended' => [
            'title' => 'تم تمديد الحجز',
            'body' => 'تم تمديد حجزك لـ :space حتى :end_time.',
        ],
        'cancelled' => [
            'title' => 'تم إلغاء حجزك',
            'body' => 'ألغى فريق ADD حجزك لـ :space يوم :date، وأُعيد :points نقطة إلى محفظتك.',
        ],
        'cancelled_no_refund' => [
            'title' => 'تم إلغاء حجزك',
            'body' => 'ألغى فريق ADD حجزك لـ :space يوم :date.',
        ],
        'affected_by_maintenance' => [
            'title' => 'حجزك متأثر بأعمال صيانة',
            'body' => 'دخلت :space صيانة طارئة، وسيتواصل معك فريقنا بخصوص حجزك.',
        ],
    ],

    'access' => [
        'passcode_ready' => [
            'title' => 'رمز دخولك جاهز',
            'body' => 'رمز باب :space جاهز من :from حتى :to. افتح التطبيق لعرضه.',
        ],
        'revoked' => [
            'title' => 'أُوقف رمز الدخول',
            'body' => 'أُوقف رمز باب :space بسبب صيانة طارئة.',
        ],
        'office_opened' => [
            'title' => 'مكتبك جاهز',
            'body' => 'أصبح باب :space متاحاً لك من اليوم.',
        ],
        'grace_expiring' => [
            'title' => 'اقتراب نهاية وقت الوصول',
            'body' => 'ينتهي وقت وصولك لـ :space خلال :minutes دقيقة.',
        ],
    ],

    'reception' => [
        'arrival_queued' => [
            'title' => 'تسجيل وصول عند الكيوسك',
            'body' => 'قام العضو :member_name بتسجيل وصوله عند كيوسك الاستقبال.',
        ],
        'arrival_confirmed' => [
            'title' => 'أهلاً بك في ADD',
            'body' => 'تم تأكيد وصولك إلى فرع :branch. نهارك سعيد ومثمر!',
        ],
        'forgotten_session_alert' => [
            'title' => 'جلسة أُغلقت تلقائياً',
            'body' => 'أُغلقت جلسة :member في :space لعدم تسجيل الخروج.',
        ],
        'session_settled' => [
            'title' => 'تم تسوية الجلسة والخروج',
            'body' => 'تم دفع وتسوية فاتورة جلستك بقيمة :amount :currency.',
        ],
    ],

    'wallet' => [
        'topped_up' => [
            'title' => 'تم شحن المحفظة',
            'body' => 'تمت إضافة :amount :currency (:points نقطة) إلى محفظتك.',
        ],
        'company_allocated' => [
            'title' => 'وصلك رصيد من شركتك',
            'body' => 'خصّصت :company لك :points نقطة لاستخدامها في :category.',
        ],
        'categories' => [
            'general' => 'الاستخدام العام',
            'cafe' => 'المقهى',
            'printing_internet' => 'الطباعة والإنترنت',
            'space_specific' => 'مساحة محددة',
        ],
        'points_expiring' => [
            'title' => 'نقاط تقترب من الانتهاء',
            'body' => 'ستنتهي صلاحية :points نقطة من محفظتك بتاريخ :date.',
        ],
    ],

    'membership' => [
        'desk_needed' => [
            'title' => 'مكتب مخصص بانتظار الربط',
            'body' => 'اشترك عضو في :plan من التطبيق ويحتاج إلى ربط مقعد.',
        ],
        'assigned' => [
            'title' => 'تم تفعيل باقتك',
            'body' => 'أصبحت باقة :plan مفعّلة لك من :start حتى :end.',
        ],
        'started' => [
            'title' => 'بدأ اشتراكك',
            'body' => 'بدأ اشتراكك في :plan اليوم وأُضيفت أرصدته إلى محفظتك.',
        ],
        'renewed' => [
            'title' => 'تم تجديد الاشتراك',
            'body' => 'جُدّد اشتراك :plan حتى :date.',
        ],
        'desk_assigned' => [
            'title' => 'مكتبك المخصص جاهز',
            'body' => 'خُصّص لك المكتب :desk في :space.',
        ],
        'expiring_soon' => [
            'title' => 'باقتك تنتهي قريباً',
            'body' => 'ينتهي اشتراكك في :plan بتاريخ :date. جدّد من التطبيق لتحافظ على أرصدتك.',
        ],
        'expired' => [
            'title' => 'انتهت باقتك',
            'body' => 'انتهى اشتراكك في :plan. تصفّح الباقات لتجديد اشتراكك.',
        ],
    ],

    'company' => [
        'member_joined' => [
            'title' => 'تمت إضافتك إلى :company',
            'body' => 'أصبحت عضواً في فريق :company في ADD.',
        ],
        'member_removed' => [
            'title' => 'أُزلت من :company',
            'body' => 'لم تعد عضواً في فريق :company.',
        ],
        'door_access_granted' => [
            'title' => 'تحديث صلاحية الأبواب',
            'body' => 'تم منحك صلاحية فتح أبواب مكاتب :company.',
        ],
        'door_access_revoked' => [
            'title' => 'تحديث صلاحية الأبواب',
            'body' => 'أُلغيت صلاحيتك لفتح أبواب مكاتب :company.',
        ],
        'admin_assigned' => [
            'title' => 'تعيينك مديراً لـ :company',
            'body' => 'مُنحت صلاحيات إدارة :company وأعضائها ومحفظتها.',
        ],
        'admin_removed' => [
            'title' => 'إلغاء إدارتك لـ :company',
            'body' => 'لم تعد مديراً لـ :company.',
        ],
    ],

    'account' => [
        'deactivated' => [
            'title' => 'تم تعطيل الحساب',
            'body' => 'تم تعطيل حسابك في ADD.',
        ],
        'blocked' => [
            'title' => 'تم حظر الحساب',
            'body' => 'تم تجميد حسابك مؤقتاً. يرجى التواصل مع إدارة ADD.',
        ],
        'activated' => [
            'title' => 'تم تفعيل الحساب',
            'body' => 'تم إعادة تفعيل حسابك. أهلاً بعودتك!',
        ],
    ],

    'inquiry' => [
        'new_received' => [
            'title' => 'استفسار موقع جديد',
            'body' => 'ورد استفسار جديد من :sender_name بخصوص :subject.',
        ],
    ],

    'announcement' => [
        'new' => [
            'title' => 'إعلان جديد',
            'body' => ':title',
        ],
    ],

    'service_request' => [
        'submitted' => [
            'title' => 'طلب خدمة جديد',
            'body' => ':member طلب :service.',
        ],
        'status_updated' => [
            'title' => 'تحديث على طلبك',
            'body' => 'أصبحت حالة طلب :service: :status.',
        ],
        'statuses' => [
            'pending' => 'قيد الانتظار',
            'in_progress' => 'قيد التنفيذ',
            'resolved' => 'تم الحل',
            'rejected' => 'مرفوض',
            'cancelled' => 'ملغى',
        ],
    ],

    'coworking' => [
        'session_started' => [
            'title' => 'بدأت الجلسة',
            'body' => 'أكّد الاستقبال وجودك. بدأت جلستك في :space.',
        ],
        'start_rejected' => [
            'title' => 'لم تبدأ الجلسة',
            'body' => 'لم يؤكد الاستقبال جلستك. يرجى مراجعة مكتب الاستقبال.',
        ],
        'session_ended' => [
            'title' => 'انتهت الجلسة',
            'body' => 'انتهت جلستك في :space. اضغط لعرض التقرير.',
        ],
        'session_ended_unpaid' => [
            'title' => 'انتهت الجلسة',
            'body' => 'انتهت جلستك في :space. اضغط لاختيار طريقة الدفع.',
        ],
        'arrival_expired' => [
            'title' => 'لم يُستلم طلبك',
            'body' => 'لم يؤكد الاستقبال وصولك خلال المهلة. امسح الرمز مجدداً أو راجع المكتب.',
        ],
        'closed_by_system' => [
            'title' => 'أُغلقت جلستك تلقائياً',
            'body' => 'أُغلقت جلستك في :space الساعة :time لأن الخروج لم يُسجَّل. المستحق: :points نقطة.',
        ],
        'closed_by_system_no_charge' => [
            'title' => 'أُغلقت جلستك تلقائياً',
            'body' => 'أُغلقت جلستك في :space الساعة :time لأن الخروج لم يُسجَّل.',
        ],
        'plan_cover_ending' => [
            'title' => 'تنتهي تغطية باقتك بعد :minutes دقائق',
            'body' => 'تنتهي تغطية باقتك لجلستك في :space الساعة :time، وما بعدها يُحتسب بسعر المساحة.',
        ],
        'unpaid_reminder' => [
            'title' => 'جلسة غير مدفوعة',
            'body' => 'ما زال عليك :points نقطة لجلسة :date. اضغط للدفع.',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title' => 'فاتورة جديدة',
            'body' => 'صدرت الفاتورة :number بقيمة :amount :currency، وتستحق في :due.',
        ],
        'overdue' => [
            'title' => 'فاتورة متأخرة',
            'body' => 'تجاوزت الفاتورة :number موعد استحقاقها. المتبقي: :amount :currency.',
        ],
        'overdue_digest' => [
            'title' => 'فواتير متأخرة',
            'body' => ':count فواتير تجاوزت موعد استحقاقها.',
        ],
        'payment_recorded' => [
            'title' => 'تم تسجيل دفعة',
            'body' => 'سُجّلت :amount :currency على الفاتورة :number. المتبقي :remaining.',
        ],
    ],

    'renewal' => [
        'done' => [
            'title' => 'تم التجديد',
            'body' => 'جُدّد :subject لشركة :company حتى :date.',
        ],
        'stopped' => [
            'title' => 'توقف تجديد تلقائي',
            'body' => 'توقف تجديد :subject لـ :company: :reason',
        ],
        // What was renewed, as :subject.
        'subjects' => [
            'membership' => 'باقة :plan',
            'lease' => 'عقد :space',
        ],
    ],

    'private_office' => [
        'requested' => [
            'title' => 'طلب مكتب خاص جديد',
            'body' => ':provider طلب مكتباً خاصاً لـ :name.',
        ],
    ],

    'exchange_rate' => [
        'suggested' => [
            'title' => 'سعر صرف مقترح',
            'body' => 'سعر جديد لـ :currency: :rate. راجعه واعتمده.',
        ],
    ],

    'lease' => [
        'contracted' => [
            'title' => 'تم توقيع عقد المكتب',
            'body' => 'عقد :space يبدأ :start وينتهي :end.',
        ],
        'ending_soon' => [
            'title' => 'عقد مكتبك ينتهي قريباً',
            'body' => 'ينتهي عقد :space بتاريخ :date.',
        ],
        'ended' => [
            'title' => 'انتهى عقد المكتب',
            'body' => 'تتوقف صلاحية باب :space من :date.',
        ],
    ],

    'payment' => [
        'received' => [
            'title' => 'تم استلام الدفعة',
            'body' => 'تم استلام دفعتك مقابل :purpose (:points نقطة).',
        ],
        // Money handed over at the reception desk (PaymentNotifier::receivedAtReception).
        'received_at_reception' => [
            'title' => 'تم تسجيل دفعتك في الاستقبال',
            'body' => 'استلم الاستقبال :amount مقابل :purpose (:points نقطة). اكتملت عملية الدفع بنجاح.',
        ],
        'received_at_reception_with_change' => [
            'title' => 'تم تسجيل دفعتك في الاستقبال',
            'body' => 'استلم الاستقبال :amount مقابل :purpose (:points نقطة). اكتملت عملية الدفع بنجاح، وأُضيف الباقي :change نقطة إلى محفظتك.',
        ],
        'expired' => [
            'title' => 'تم إلغاء حجز الباقة',
            'body' => 'لم يتم دفع الباقة التي اخترت دفعها في الاستقبال خلال المهلة، وتم إلغاء حجزها.',
        ],
        'failed' => [
            'title' => 'لم تكتمل عملية الدفع',
            'body' => 'لم تتم عملية الدفع مقابل :purpose، ولم يُخصم شيء. يمكنك المحاولة مجدداً.',
        ],
        'needs_review' => [
            'title' => 'دفعة تحتاج مراجعة',
            'body' => 'دفعة البطاقة رقم :id بقيمة :points نقطة تحتاج مراجعة يدوية.',
        ],
        'reversed' => [
            'title' => 'تم عكس دفعة',
            'body' => 'عُكست دفعتك مقابل :purpose بقيمة :points نقطة. تواصل معنا لأي استفسار.',
        ],
        'purposes' => [
            'wallet_top_up' => 'شحن المحفظة',
            'plan_purchase' => 'باقة',
            'booking' => 'حجز',
            'event_registration' => 'تسجيل في فعالية',
            'coworking_session' => 'جلسة عمل مشترك',
            'booking_extension' => 'تمديد حجز',
            'item_sale' => 'مشتريات من الاستقبال',
        ],
    ],

    'event' => [
        'payment_due' => [
            'title' => 'دفعة مستحقة',
            'body' => 'تم تأكيد مقعدك في :event. ادفع :points نقطة قبل الفعالية لتتمكن من الدخول.',
        ],
        'registration_pending' => [
            'title' => 'تسجيل بانتظار الموافقة',
            'body' => ':member سجّل في :event.',
        ],
        'registration_approved' => [
            'title' => 'تم قبول تسجيلك',
            'body' => 'مقعدك في :event يوم :date مؤكد.',
        ],
        'registration_rejected' => [
            'title' => 'لم يُقبل تسجيلك',
            'body' => 'نعتذر، لم يُقبل تسجيلك في :event.',
        ],
        'registration_expired' => [
            'title' => 'انتهت مهلة تسجيلك',
            'body' => 'لم يُدفع تسجيلك في :event خلال المهلة وأُلغي المقعد.',
        ],
        'reminder' => [
            'title' => 'فعاليتك تقترب',
            'body' => ':event يبدأ :date الساعة :time في :location.',
        ],
        'reminder_without_location' => [
            'title' => 'فعاليتك تقترب',
            'body' => ':event يبدأ :date الساعة :time.',
        ],
    ],

    'provider_request' => [
        'submitted' => [
            'title' => 'طلب جديد من :provider',
            'body' => 'طلب :subject.',
        ],
    ],

    'provider' => [
        'employee_joined' => [
            'title' => 'انضم موظف جديد',
            'body' => 'انضم :name إلى :company.',
        ],
        'request_approved' => [
            'title' => 'تمت الموافقة على طلبك',
            'body' => 'وافقت ADD على طلب :subject.',
        ],
        'request_rejected' => [
            'title' => 'رُفض طلبك',
            'body' => 'رفضت ADD طلب :subject. السبب: :reason',
        ],
        // What a request was for, as :subject: its type, and its company when it has one.
        'request_subject_with_company' => ':type لشركة :company',
        'request_types' => [
            'company_member' => 'إضافة موظف',
            'company_admin' => 'تعيين مدير شركة',
            'plan_design' => 'تصميم باقة',
            'plan_assignment' => 'تفعيل باقة',
            'wallet_top_up' => 'شحن المحفظة',
        ],
    ],

    // الموارد البشرية (packages/add/hr)، عبر App\Domain\Hr\Adapters\AddHrNotifier.
    'hr' => [
        'alerts_digest' => [
            'title' => 'تنبيهات الموارد البشرية',
            'body' => ':count تنبيهات تحتاج متابعة (عقود، فترات تجربة، وثائق).',
        ],
        'approval_pending' => [
            'title' => 'طلب بانتظار موافقتك',
            'body' => 'يوجد طلب بانتظار قرارك.',
        ],
        'approval_escalated' => [
            'title' => 'طلب متأخر',
            'body' => 'تجاوز طلبٌ مهلة الموافقة وأُحيل إليك.',
        ],
        'request_approved' => [
            'title' => 'تمت الموافقة على طلبك',
            'body' => 'تمت الموافقة على طلبك.',
        ],
        'request_rejected' => [
            'title' => 'رُفض طلبك',
            'body' => 'تم رفض طلبك.',
        ],
        'payslip_issued' => [
            'title' => 'قسيمة راتبك جاهزة',
            'body' => 'صدرت قسيمة راتبك لشهر :month.',
        ],
        'benefit_granted' => [
            'title' => 'وصلتك ميزة',
            'body' => 'أُضيفت إلى محفظتك :amount نقطة من ميزة :benefit_ar.',
        ],
        'benefit_grants_failed' => [
            'title' => 'مزايا لم تُنفَّذ',
            'body' => 'تعذّر تنفيذ :count من منح المزايا لشهر :month. راجعها وأعد المحاولة.',
        ],
        'interview_scheduled' => [
            'title' => 'لديك مقابلة توظيف',
            'body' => 'مقابلة مع :candidate على وظيفة :opening_ar في :at.',
        ],
        'review_self_open' => [
            'title' => 'بدأ تقييمك الذاتي',
            'body' => 'قيّم نفسك على أهدافك وكفاءاتك في :cycle_ar.',
        ],
        'review_manager_open' => [
            'title' => 'تقييمات بانتظارك',
            'body' => 'قيّم الموظفين الذين تقيّمهم في :cycle_ar.',
        ],
        'review_finalized' => [
            'title' => 'نتيجة تقييمك جاهزة',
            'body' => 'صدرت نتيجة :cycle_ar.',
        ],
        'training_enrolled' => [
            'title' => 'سُجّلت في تدريب',
            'body' => 'يبدأ :course_ar في :starts_on.',
        ],
        'training_cancelled' => [
            'title' => 'أُلغي تدريب',
            'body' => 'أُلغيت جلسة :course_ar المقررة في :starts_on.',
        ],
    ],

    // Inventory (packages/add/inventory), sent through
    // App\Domain\Inventory\Adapters\AddInventoryNotifier.
    'inventory' => [
        'maintenance_due' => [
            'title' => 'صيانة مستحقة',
            'body' => ':title للأصل :asset مستحقة في :date.',
        ],
        'warranty_ending' => [
            'title' => 'ضمان على وشك الانتهاء',
            'body' => 'ينتهي ضمان الأصل :asset في :date.',
        ],
    ],

];
