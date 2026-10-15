<?php

namespace Database\Seeders;

use App\Domain\Ecosystem\Models\TermsOfUse;
use Illuminate\Database\Seeder;

/**
 * The app's terms of use, Markdown in each language, read from
 * database/seeders/content/terms-of-use.{ar,en}.md. Only when there are no
 * terms yet: terms edited from the dashboard are never overwritten.
 */
class TermsOfUseSeeder extends Seeder
{
    public function run(): void
    {
        if (TermsOfUse::query()->exists()) {
            return;
        }

        TermsOfUse::create(['content' => [
            'ar' => file_get_contents(__DIR__.'/content/terms-of-use.ar.md'),
            'en' => file_get_contents(__DIR__.'/content/terms-of-use.en.md'),
        ]]);
    }
}
