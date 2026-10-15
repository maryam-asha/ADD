<?php

namespace Database\Seeders;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use Illuminate\Database\Seeder;

/**
 * The app's privacy policy, Markdown in each language, read from
 * database/seeders/content/privacy-policy.{ar,en}.md. Only when there is no
 * policy yet: one edited from the dashboard is never overwritten.
 */
class PrivacyPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (PrivacyPolicy::query()->exists()) {
            return;
        }

        PrivacyPolicy::create(['content' => [
            'ar' => file_get_contents(__DIR__.'/content/privacy-policy.ar.md'),
            'en' => file_get_contents(__DIR__.'/content/privacy-policy.en.md'),
        ]]);
    }
}
