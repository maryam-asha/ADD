<?php

namespace Tests\Feature\Mobile;

use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Ecosystem\Models\HelpSupport;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Help & Support: Markdown in one language, then the contact rows, each with
 * what tapping it opens — readable before login.
 */
class HelpSupportTest extends TestCase
{
    use RefreshDatabase;

    private function link(string $type, string $value, int $order, bool $visible = true): void
    {
        ContactLink::create(['type' => $type, 'value' => $value, 'label' => null, 'sort_order' => $order, 'is_visible' => $visible]);
    }

    public function test_the_app_reads_it_without_signing_in(): void
    {
        HelpSupport::create(['content' => ['en' => '# Help', 'ar' => '# المساعدة']]);
        $this->link('email', 'support@add.sy', 1);
        $this->link('phone', '+963 21 000 0000', 2);
        $this->link('whatsapp', '+963 944 000 000', 3);
        $this->link('address', 'حلب، سوريا', 4);
        $this->link('website', 'https://add.sy', 5);
        $this->link('instagram', 'hidden', 6, visible: false);

        $this->withHeader('lang', 'ar')->getJson('/api/v1/help-support')
            ->assertOk()
            ->assertExactJson(['data' => [
                'content' => '# المساعدة',
                'contacts' => [
                    ['type' => 'email', 'label' => null, 'value' => 'support@add.sy', 'url' => 'mailto:support@add.sy'],
                    ['type' => 'phone', 'label' => null, 'value' => '+963 21 000 0000', 'url' => 'tel:+963210000000'],
                    ['type' => 'whatsapp', 'label' => null, 'value' => '+963 944 000 000', 'url' => 'https://wa.me/963944000000'],
                    ['type' => 'address', 'label' => null, 'value' => 'حلب، سوريا', 'url' => 'https://www.google.com/maps/search/?api=1&query='.rawurlencode('حلب، سوريا')],
                    ['type' => 'website', 'label' => null, 'value' => 'https://add.sy', 'url' => 'https://add.sy'],
                ],
            ]]);

        $this->withHeader('lang', 'en')->getJson('/api/v1/help-support')->assertJsonPath('data.content', '# Help');
    }

    public function test_no_text_yet_is_null_content(): void
    {
        $this->getJson('/api/v1/help-support')->assertOk()->assertExactJson(['data' => ['content' => null, 'contacts' => []]]);
    }

    public function test_the_dashboard_edits_both_languages(): void
    {
        $this->seed(RoleSeeder::class);
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->patchJson('/api/v1/admin/help-support', ['content' => ['en' => '# Help', 'ar' => '# المساعدة']])->assertOk();
        $this->patchJson('/api/v1/admin/help-support', ['content' => ['en' => '# Help 2', 'ar' => '# المساعدة 2']])->assertOk();

        $this->assertSame(['en' => '# Help 2', 'ar' => '# المساعدة 2'], HelpSupport::sole()->content);
        $this->getJson('/api/v1/admin/help-support')->assertJsonPath('data.content', ['en' => '# Help 2', 'ar' => '# المساعدة 2']);
        $this->patchJson('/api/v1/admin/help-support', ['content' => ['en' => 'x']])->assertJsonValidationErrors('content.ar');
    }
}
