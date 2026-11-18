<?php

namespace Tests\Unit\Lang;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class ApiTranslationTest extends TestCase
{
    public function test_every_api_key_resolves_in_english(): void
    {
        App::setLocale('en');

        $this->assertSame('These credentials do not match our records.', __('api.auth.invalid_credentials'));
        $this->assertSame('This account has been suspended. Please contact ADD.', __('api.auth.account_inactive'));
        $this->assertSame('Too many attempts. Please wait before trying again.', __('api.auth.too_many_attempts'));
        $this->assertSame('Unauthenticated.', __('api.auth.unauthenticated'));
        $this->assertSame('This action is unauthorized.', __('api.auth.forbidden'));
        $this->assertSame('Insufficient general balance to allocate this amount.', __('api.wallet.insufficient_balance'));
        $this->assertSame('The requested resource was not found.', __('api.system.not_found'));
        $this->assertSame('An unexpected error occurred. Please try again later.', __('api.system.server_error'));
        $this->assertSame('The given data is invalid.', __('api.validation.failed'));
    }

    public function test_every_api_key_resolves_in_arabic(): void
    {
        App::setLocale('ar');

        $this->assertSame('بيانات الدخول غير مطابقة لسجلاتنا.', __('api.auth.invalid_credentials'));
        $this->assertSame('هذا الحساب معلّق. الرجاء التواصل مع ADD.', __('api.auth.account_inactive'));
        $this->assertSame('محاولات كثيرة جداً. الرجاء الانتظار قبل المحاولة مرة أخرى.', __('api.auth.too_many_attempts'));
        $this->assertSame('غير مصادَق.', __('api.auth.unauthenticated'));
        $this->assertSame('غير مخوّل بتنفيذ هذا الإجراء.', __('api.auth.forbidden'));
        $this->assertSame('الرصيد العام غير كافٍ لتخصيص هذا المبلغ.', __('api.wallet.insufficient_balance'));
        $this->assertSame('المورد المطلوب غير موجود.', __('api.system.not_found'));
        $this->assertSame('حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.', __('api.system.server_error'));
        $this->assertSame('البيانات المُرسلة غير صالحة.', __('api.validation.failed'));
    }

    public function test_every_auth_flow_key_resolves_in_both_locales(): void
    {
        $keys = [
            'auth.otp_request_throttled',
            'auth.otp_sent',
            'auth.registration_code_invalid',
            'auth.account_already_exists',
            'auth.phone_already_registered',
            'auth.code_purpose_mismatch_reset',
            'auth.code_purpose_mismatch_registration',
            'auth.code_invalid',
            'auth.refresh_token_invalid',
            'auth.logged_out',
            'auth.password_reset_code_sent',
            'auth.password_updated',
        ];

        foreach (['en', 'ar'] as $locale) {
            App::setLocale($locale);

            foreach ($keys as $key) {
                $this->assertNotSame("api.{$key}", __("api.{$key}"), "Missing {$locale} translation for {$key}");
            }
        }
    }
}
