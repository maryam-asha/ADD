<?php

namespace Add\Hr\Tests\Unit;

use Add\Hr\Support\Concerns\HasTranslations;
use Add\Hr\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class HasTranslationsTest extends TestCase
{
    private function model(array $name): Model
    {
        $model = new class extends Model
        {
            use HasTranslations;

            protected $casts = ['name' => 'array'];
        };
        $model->name = $name;

        return $model;
    }

    public function test_reads_the_current_locale(): void
    {
        app()->setLocale('ar');

        $this->assertSame('أ', $this->model(['ar' => 'أ', 'en' => 'A'])->translate('name'));
    }

    public function test_falls_back_to_arabic_when_english_is_missing(): void
    {
        app()->setLocale('en');

        $this->assertSame('أ', $this->model(['ar' => 'أ'])->translate('name'));
    }

    public function test_an_explicit_locale_wins(): void
    {
        app()->setLocale('ar');

        $this->assertSame('A', $this->model(['ar' => 'أ', 'en' => 'A'])->translate('name', 'en'));
    }
}
