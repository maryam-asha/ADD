<?php

namespace Database\Seeders;

use App\Domain\Ecosystem\Models\HelpSupport;
use Illuminate\Database\Seeder;

/**
 * The app's Help & Support text, Markdown in each language, read from
 * database/seeders/content/help-support.{ar,en}.md. Only when there is none
 * yet: text edited from the dashboard is never overwritten. The page's
 * contact rows come from ContactLinkSeeder.
 */
class HelpSupportSeeder extends Seeder
{
    public function run(): void
    {
        if (HelpSupport::query()->exists()) {
            return;
        }

        HelpSupport::create(['content' => [
            'ar' => file_get_contents(__DIR__.'/content/help-support.ar.md'),
            'en' => file_get_contents(__DIR__.'/content/help-support.en.md'),
        ]]);
    }
}
