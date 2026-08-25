<?php

return [

    'auth' => [
        'otp_request_throttled' => 'طلبات كثيرة. الرجاء الانتظار قبل طلب رمز جديد.',
        'otp_sent' => 'تم إرسال رمز التحقق.',
        'registration_code_invalid' => 'الرمز غير صحيح أو منتهي الصلاحية. الرجاء إعادة التسجيل.',
        'account_already_exists' => 'يوجد حساب مسجّل بهذا الرقم أو البريد الإلكتروني مسبقاً. الرجاء تسجيل الدخول.',
        'phone_already_registered' => 'هذا الرقم مسجّل بحساب مسبقاً. الرجاء تسجيل الدخول.',
        'code_purpose_mismatch_reset' => 'هذا الرمز مخصّص لإعادة تعيين كلمة المرور، لا لإنشاء حساب.',
        'code_purpose_mismatch_registration' => 'هذا الرمز مخصّص لإنشاء حساب، لا لإعادة تعيين كلمة المرور.',
        'code_invalid' => 'الرمز غير صحيح أو منتهي الصلاحية.',
        'account_inactive' => 'هذا الحساب معلّق. الرجاء التواصل مع ADD.',
        'invalid_credentials' => 'بيانات الدخول غير مطابقة لسجلاتنا.',
        'refresh_token_invalid' => 'رمز التحديث غير صحيح أو منتهي الصلاحية.',
        'logged_out' => 'تم تسجيل الخروج.',
        'password_reset_code_sent' => 'إذا كان هذا الرقم مرتبطاً بحساب، فسيتم إرسال رمز إعادة التعيين إليه.',
        'otp_verified' => 'تم التحقق من الرمز.',
        'reset_token_invalid' => 'رمز إعادة التعيين غير صحيح أو منتهي الصلاحية. الرجاء طلب رمز جديد.',
        'password_updated' => 'تم تحديث كلمة المرور. الرجاء تسجيل الدخول بكلمة المرور الجديدة.',
        'too_many_attempts' => 'محاولات كثيرة جداً. الرجاء الانتظار قبل المحاولة مرة أخرى.',
        'unauthenticated' => 'غير مصادَق.',
        'forbidden' => 'غير مخوّل بتنفيذ هذا الإجراء.',
        'already_authenticated' => 'أنت مسجّل الدخول بالفعل.',
        'account_deleted' => 'تم حذف الحساب.',
        'account_deactivated' => 'تم تعطيل الحساب.',
        'account_reactivation_code_sent' => 'إذا كان هذا الرقم مرتبطاً بحساب معطّل، فسيتم إرسال رمز إعادة التفعيل إليه.',
        'code_purpose_mismatch' => 'هذا الرمز مخصّص لإجراء آخر.',
        'current_password_incorrect' => 'كلمة المرور الحالية غير صحيحة.',
        'password_changed' => 'تم تحديث كلمة المرور.',
    ],

    'wallet' => [
        'insufficient_balance' => 'الرصيد العام غير كافٍ لتخصيص هذا المبلغ.',
        'insufficient_balance_for_plan' => 'الرصيد العام غير كافٍ لشراء هذه الباقة.',
    ],

    'system' => [
        'not_found' => 'المورد المطلوب غير موجود.',
        'server_error' => 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',
        'updated' => 'تم التحديث بنجاح.',
        'consent_recorded' => 'تم تسجيل الموافقة.',
    ],

    'validation' => [
        'failed' => 'البيانات المُرسلة غير صالحة.',
    ],

    'member' => [
        'currency_preference_updated' => 'تم تحديث تفضيل العملة.',
        'language_preference_updated' => 'تم تحديث تفضيل اللغة.',
        'profile_updated' => 'تم تحديث الملف الشخصي.',
        'consent_updated' => 'تم تحديث الموافقة.',
        'door_access_updated' => 'تم تحديث صلاحية الدخول.',
        'admin_flag_updated' => 'تم تحديث صلاحية الإدارة.',
    ],

    'profile' => [
        'image_updated' => 'تم تحديث صورة الملف الشخصي.',
    ],

    'mobile' => [
        'error_logged' => 'تم استلام تقرير الخطأ.',
    ],

    'admin' => [
        'branch_updated' => 'تم تحديث الفرع.',
        'building_updated' => 'تم تحديث المبنى.',
        'floor_updated' => 'تم تحديث الطابق.',
        'zone_updated' => 'تم تحديث المنطقة.',
        'space_updated' => 'تم تحديث المساحة.',
        'space_status_updated' => 'تم تحديث حالة المساحة.',
        'resource_updated' => 'تم تحديث المورد.',
        'resource_status_updated' => 'تم تحديث حالة المورد.',
        'seat_desk_updated' => 'تم تحديث المقعد/المكتب.',
        'device_updated' => 'تم تحديث الجهاز.',
        'device_capability_updated' => 'تم تحديث خاصية الجهاز.',
        'founder_updated' => 'تم تحديث المؤسس.',
        'partner_updated' => 'تم تحديث الشريك.',
        'contact_link_updated' => 'تم تحديث رابط التواصل.',
        'plan_updated' => 'تم تحديث الباقة.',
        'community_member_updated' => 'تم تحديث عضو المجتمع.',
        'private_office_request_updated' => 'تم تحديث طلب المكتب الخاص.',
        'company_status_updated' => 'تم تحديث حالة الشركة.',
        'company_member_door_access_updated' => 'تم تحديث صلاحية دخول العضو.',
        'company_member_admin_updated' => 'تم تحديث صلاحية إدارة العضو.',
        'user_updated' => 'تم تحديث المستخدم.',
        'user_status_updated' => 'تم تحديث حالة المستخدم.',
        'user_role_updated' => 'تم تحديث دور المستخدم.',
        'setting_updated' => 'تم تحديث الإعداد.',
        'business_hour_updated' => 'تم تحديث ساعات العمل.',
        'business_hour_exception_updated' => 'تم تحديث استثناء ساعات العمل.',
        'currency_updated' => 'تم تحديث العملة.',
        'currency_status_updated' => 'تم تحديث حالة العملة.',
        'announcement_updated' => 'تم تحديث الإعلان.',
        'exchange_rate_suggestion_dismissed' => 'تم رفض اقتراح سعر الصرف.',
        'exchange_rate_suggestion_not_pending' => 'يمكن رفض الاقتراح المعلّق فقط.',
    ],

    'currency' => [
        'base_currency_status_locked' => 'لا يمكن تعطيل العملة الأساسية.',
    ],

    'reception' => [
        'checked_in' => 'تم تسجيل الدخول.',
        'checked_out' => 'تم تسجيل الخروج.',
        'cancelled' => 'تم إلغاء الحجز.',
        'payment_settled' => 'تم تسوية الدفع.',
        'already_checked_in' => 'تم تسجيل دخول هذا الحجز مسبقاً.',
        'already_checked_out' => 'تم تسجيل خروج هذه الجلسة مسبقاً.',
        'already_cancelled' => 'تم إلغاء هذا الحجز مسبقاً.',
        'already_paid' => 'تمت تسوية دفع هذا الحجز أو الجلسة مسبقاً.',
        'outside_business_hours' => 'هذا الإجراء غير متاح خارج ساعات العمل.',
        'no_capacity' => 'لا توجد سعة متاحة لهذه المساحة حالياً.',
        'not_checked_in' => 'لم يتم تسجيل دخول هذه الجلسة بعد.',
        'checkout_before_checkin' => 'لا يمكن أن يكون وقت تسجيل الخروج قبل وقت تسجيل الدخول.',
        'checkout_past_closing' => 'لا يمكن أن يكون وقت تسجيل الخروج بعد وقت إغلاق الفرع.',
        'not_yet_checked_out' => 'يجب تسجيل خروج هذا الحجز أو الجلسة قبل تسوية الدفع.',
        'cancellation_window_passed' => 'تجاوز هذا الحجز مهلة الإلغاء المسموحة.',
    ],

    'booking' => [
        'invalid_start_time' => 'وقت البدء لا يتوافق مع فترة الحجز المحددة لهذه المساحة.',
        'duration_too_short' => 'مدة الحجز أقل من الحد الأدنى المسموح به.',
        'duration_invalid_granularity' => 'يجب أن تكون مدة الحجز من مضاعفات فترة الحجز المحددة لهذه المساحة فوق الحد الأدنى.',
        'slot_unavailable' => 'هذه المساحة غير متاحة في الوقت المطلوب.',
        'buffer_conflict' => 'هذا الحجز قريب جداً من حجز آخر على هذه المساحة.',
        'wallet_choice_required' => 'يمكن لأكثر من محفظة تغطية هذا الحجز. الرجاء اختيار واحدة.',
        'not_pending' => 'هذا الحجز ليس في انتظار الموافقة.',
        'rejection_reason_required' => 'سبب الرفض مطلوب.',
        'approved' => 'تمت الموافقة على الحجز.',
        'rejected' => 'تم رفض الحجز.',
        'invalid_extension_duration' => 'مدة التمديد لا تستوفي الحد الأدنى للمدة أو متطلبات فترة الحجز.',
        'extension_conflict' => 'لا يمكن تمديد هذا الحجز إلى ما بعد :latest_end_at.',
        'extended' => 'تم تمديد الحجز.',
        'wallet_not_owned' => 'أنت غير مخول بالسحب من هذه المحفظة.',
        'check_in_requires_approval' => 'يجب الموافقة على هذا الحجز قبل تسجيل دخوله.',
        'check_in_rejected' => 'تم رفض هذا الحجز ولا يمكن تسجيل دخوله.',
    ],

    'kiosk' => [
        'arrival_request_not_pending' => 'طلب الوصول هذا لم يعد قيد الانتظار.',
        'space_id_required' => 'يجب تحديد مساحة لتأكيد طلب وصول غير مطابق لحجز.',
        'arrival_request_confirmed' => 'تم تأكيد الوصول.',
        'arrival_request_rejected' => 'تم رفض طلب الوصول.',
    ],

];
