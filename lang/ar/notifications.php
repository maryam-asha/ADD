<?php

return [

    'booking' => [
        'pending_approval' => [
            'title' => 'حجز بانتظار موافقتك',
            'body' => ':member طلب :space يوم :date الساعة :time.',
        ],
        'approved' => [
            'title' => 'تمت الموافقة على حجزك',
            'body' => 'حجزك لـ :space يوم :date الساعة :time مؤكد.',
        ],
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
        'reminder' => [
            'title' => 'تذكير بموعد حجزك',
            'body' => 'سيبدأ حجزك لـ :space خلال :minutes دقيقة.',
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
            'title' => 'تنبيه جلسة غير مغلقة',
            'body' => 'جلسة مفتوحة للعضو :member_name تم إغلاق فوترتها تلقائياً عند منتصف الليل.',
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
            'title' => 'اقتراب موعد انتهاء الباقة',
            'body' => 'سينتهي اشتراكك في باقة :plan_name خلال :days أيام.',
        ],
        'expired' => [
            'title' => 'انتهت صلاحية الباقة',
            'body' => 'انتهت صلاحية اشتراكك في باقة :plan_name.',
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
    ],

    'invoice' => [
        'issued' => [
            'title' => 'فاتورة جديدة',
            'body' => 'صدرت الفاتورة :number بقيمة :amount :currency، وتستحق في :due.',
        ],
    ],

    'lease' => [
        'contracted' => [
            'title' => 'تم توقيع عقد المكتب',
            'body' => 'عقد :space يبدأ :start وينتهي :end.',
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
        'expired' => [
            'title' => 'تم إلغاء حجز الباقة',
            'body' => 'لم يتم دفع الباقة التي اخترت دفعها في الاستقبال خلال المهلة، وتم إلغاء حجزها.',
        ],
        'failed' => [
            'title' => 'لم تكتمل عملية الدفع',
            'body' => 'لم تتم عملية الدفع مقابل :purpose، ولم يُخصم شيء. يمكنك المحاولة مجدداً.',
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
    ],

    'provider' => [
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
