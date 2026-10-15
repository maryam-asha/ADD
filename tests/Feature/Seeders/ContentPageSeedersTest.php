<?php

namespace Tests\Feature\Seeders;

use App\Domain\Ecosystem\Models\HelpSupport;
use App\Domain\Ecosystem\Models\PrivacyPolicy;
use App\Domain\Ecosystem\Models\TermsOfUse;
use Database\Seeders\HelpSupportSeeder;
use Database\Seeders\PrivacyPolicySeeder;
use Database\Seeders\TermsOfUseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentPageSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_they_write_both_languages_as_markdown(): void
    {
        $this->seed([PrivacyPolicySeeder::class, TermsOfUseSeeder::class, HelpSupportSeeder::class]);

        $policy = PrivacyPolicy::sole()->content;
        $terms = TermsOfUse::sole()->content;

        $this->assertStringStartsWith('# سياسة الخصوصية', $policy['ar']);
        $this->assertStringStartsWith('# Privacy Policy', $policy['en']);
        $this->assertStringStartsWith('# شروط استخدام', $terms['ar']);
        $this->assertStringStartsWith('# Terms of Use', $terms['en']);
        $this->assertStringStartsWith('# المساعدة والدعم', HelpSupport::sole()->content['ar']);
        $this->assertStringStartsWith('# Help & Support', HelpSupport::sole()->content['en']);
    }

    /** Running them again, or after an edit from the dashboard, changes nothing. */
    public function test_they_never_overwrite_existing_text(): void
    {
        PrivacyPolicy::create(['content' => ['ar' => 'معدّلة', 'en' => 'Edited']]);
        TermsOfUse::create(['content' => ['ar' => 'معدّلة', 'en' => 'Edited']]);
        HelpSupport::create(['content' => ['ar' => 'معدّلة', 'en' => 'Edited']]);

        $this->seed([PrivacyPolicySeeder::class, TermsOfUseSeeder::class, HelpSupportSeeder::class]);

        $this->assertSame(['ar' => 'معدّلة', 'en' => 'Edited'], PrivacyPolicy::sole()->content);
        $this->assertSame(['ar' => 'معدّلة', 'en' => 'Edited'], TermsOfUse::sole()->content);
        $this->assertSame(['ar' => 'معدّلة', 'en' => 'Edited'], HelpSupport::sole()->content);
    }
}
