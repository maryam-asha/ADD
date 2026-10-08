<?php

return [

    'booking' => [
        'pending_approval' => [
            'title' => 'حجز جديد بانتظار الموافقة',
            'body' => 'طلب حجز جديد لـ :space من قِبل :member_name يتطلب الموافقة.',
        ],
        'approved' => [
            'title' => 'تمت الموافقة على الحجز',
            'body' => 'تمت الموافقة على حجزك لـ :space بتاريخ :date.',
        ],
        'rejected' => [
            'title' => 'تم رفض الحجز',
            'body' => 'تم رفض حجزك لـ :space. السبب: :reason',
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
            'title' => 'تم إلغاء الحجز',
            'body' => 'تم إلغاء حجزك لـ :space واسترجاع المبلغ إلى محفظتك.',
        ],
        'affected_by_maintenance' => [
            'title' => 'حجزك متأثر بأعمال صيانة',
            'body' => 'نعتذر، دخلت المساحة :space أعمال صيانة طارئة وسيتواصل معك فريق العمليات بخصوص حجزك.',
        ],
    ],

    'access' => [
        'passcode_ready' => [
            'title' => 'رمز الوصول جاهز',
            'body' => 'رمز فتح قفل :space هو :code (أو يمكنك فتح الباب عبر التطبيق).',
        ],
        'revoked_maintenance' => [
            'title' => 'تم إبطال رمز الوصول',
            'body' => 'تم إبطال رمز الدخول لـ :space مؤقتاً لدواعي الصيانة.',
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
            'title' => 'تم تخصيص رصيد لك',
            'body' => 'قامت شركة :company بتخصيص :points نقطة لمحفظتك لاستخدامها في :category.',
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
        'subscribed' => [
            'title' => 'تم تفعيل الباقة',
            'body' => 'أصبح اشتراكك في باقة :plan_name مفعّلاً الآن.',
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
            'title' => 'تمت إضافتك لشركة',
            'body' => 'تمت إضافتك إلى فريق شركة :company في ADD.',
        ],
        'door_access_changed' => [
            'title' => 'تحديث صلاحية فتح الأبواب',
            'body' => 'تم :status صلاحية فتح أبواب مكاتب شركة :company.',
        ],
        'admin_assigned' => [
            'title' => 'تعيينك كمدير شركة',
            'body' => 'تم منحك صلاحيات إدارة شركة :company وأعضائها ومحفظتها.',
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

    'ticket' => [
        'created' => [
            'title' => 'تم استلام طلب الخدمة',
            'body' => 'تم إنشاء التذكرة رقم #:ticket_id لمتابعة طلبك.',
        ],
        'status_updated' => [
            'title' => 'تحديث على طلب الخدمة',
            'body' => 'أصبحت حالة التذكرة رقم #:ticket_id: :status.',
        ],
    ],

    'coworking' => [
        'session_ended' => [
            'title' => 'انتهت الجلسة',
            'body' => 'انتهت جلستك في :space. اضغط لعرض التقرير.',
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
        'purposes' => [
            'wallet_top_up' => 'شحن المحفظة',
            'plan_purchase' => 'باقة',
            'booking' => 'حجز',
            'event_registration' => 'تسجيل في فعالية',
            'coworking_session' => 'جلسة عمل مشترك',
            'booking_extension' => 'تمديد حجز',
        ],
    ],

    'event' => [
        'payment_due' => [
            'title' => 'دفعة مستحقة',
            'body' => 'تم تأكيد مقعدك في :event. ادفع :points نقطة قبل الفعالية لتتمكن من الدخول.',
        ],
    ],

    'provider' => [
        'request_approved' => [
            'title' => 'قُبل الطلب',
            'body' => 'وُوفق على طلبك.',
        ],
        'request_rejected' => [
            'title' => 'رُفض الطلب',
            'body' => 'رُفض طلبك.',
        ],
    ],

];
