<?php

namespace Tests\Unit\Lang;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

class ValidationTranslationTest extends TestCase
{
    public function test_the_required_rule_message_is_in_english_by_default(): void
    {
        App::setLocale('en');

        $this->assertSame(
            'The name field is required.',
            trans('validation.required', ['attribute' => 'name'])
        );
    }

    public function test_the_required_rule_message_is_translated_to_arabic(): void
    {
        App::setLocale('ar');

        $this->assertSame(
            'حقل الاسم مطلوب.',
            trans('validation.required', ['attribute' => 'الاسم'])
        );
    }

    public function test_the_confirmed_rule_message_is_translated_to_arabic(): void
    {
        App::setLocale('ar');

        $this->assertSame(
            'تأكيد حقل كلمة المرور غير مطابق.',
            trans('validation.confirmed', ['attribute' => 'كلمة المرور'])
        );
    }
}
