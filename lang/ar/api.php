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
        'password_updated' => 'تم تحديث كلمة المرور. الرجاء تسجيل الدخول بكلمة المرور الجديدة.',
        'too_many_attempts' => 'محاولات كثيرة جداً. الرجاء الانتظار قبل المحاولة مرة أخرى.',
        'unauthenticated' => 'غير مصادَق.',
        'forbidden' => 'غير مخوّل بتنفيذ هذا الإجراء.',
    ],

    'wallet' => [
        'insufficient_balance' => 'الرصيد العام غير كافٍ لتخصيص هذا المبلغ.',
        'insufficient_balance_for_plan' => 'الرصيد العام غير كافٍ لشراء هذه الباقة.',
    ],

    'system' => [
        'not_found' => 'المورد المطلوب غير موجود.',
        'server_error' => 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',
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

    'mobile' => [
        'error_logged' => 'تم استلام تقرير الخطأ.',
    ],

    'admin' => [
        'branch_updated' => 'تم تحديث الفرع.',
        'building_updated' => 'تم تحديث المبنى.',
        'founder_updated' => 'تم تحديث المؤسس.',
        'partner_updated' => 'تم تحديث الشريك.',
        'plan_updated' => 'تم تحديث الباقة.',
        'community_member_updated' => 'تم تحديث عضو المجتمع.',
        'private_office_request_updated' => 'تم تحديث طلب المكتب الخاص.',
        'company_status_updated' => 'تم تحديث حالة الشركة.',
        'company_member_door_access_updated' => 'تم تحديث صلاحية دخول العضو.',
        'company_member_admin_updated' => 'تم تحديث صلاحية إدارة العضو.',
        'user_updated' => 'تم تحديث المستخدم.',
        'user_status_updated' => 'تم تحديث حالة المستخدم.',
        'user_role_updated' => 'تم تحديث دور المستخدم.',
    ],

];
