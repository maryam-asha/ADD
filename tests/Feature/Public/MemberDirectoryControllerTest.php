<?php

namespace Tests\Feature\Public;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MemberDirectoryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_consenting_member_with_a_profile_appears_in_the_directory(): void
    {
        $member = User::factory()->create(['name' => 'Lina Haddad']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile', ['bio' => 'Founder.'])->assertOk();

        $response = $this->getJson('/api/v1/member-directory');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Lina Haddad']);
    }

    public function test_a_member_without_consent_is_excluded_even_with_a_filled_profile(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/profile', ['bio' => 'Founder.'])->assertOk();

        $response = $this->getJson('/api/v1/member-directory');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_a_consenting_member_with_no_profile_is_excluded(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();

        $response = $this->getJson('/api/v1/member-directory');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_revoking_consent_removes_the_member_from_the_next_read(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile', ['bio' => 'Founder.'])->assertOk();
        $this->getJson('/api/v1/member-directory')->assertJsonCount(1, 'data');

        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => false])->assertOk();

        $this->getJson('/api/v1/member-directory')->assertJsonCount(0, 'data');
    }

    public function test_response_never_includes_phone_or_email(): void
    {
        $member = User::factory()->create(['phone' => '0912345678', 'email' => 'lina@example.com']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile', ['bio' => 'Founder.'])->assertOk();

        $response = $this->getJson('/api/v1/member-directory');

        $response->assertOk();
        $response->assertJsonMissing(['phone' => '0912345678']);
        $response->assertJsonMissing(['email' => 'lina@example.com']);
    }

    public function test_a_blocked_member_is_excluded_from_the_directory(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile', ['bio' => 'Founder.'])->assertOk();
        $this->getJson('/api/v1/member-directory')->assertJsonCount(1, 'data');

        $member->block();

        $this->getJson('/api/v1/member-directory')->assertJsonCount(0, 'data');
    }

    public function test_a_deactivated_member_is_excluded_from_the_directory(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile', ['bio' => 'Founder.'])->assertOk();
        $this->getJson('/api/v1/member-directory')->assertJsonCount(1, 'data');

        $member->deactivate();

        $this->getJson('/api/v1/member-directory')->assertJsonCount(0, 'data');
    }

    public function test_results_are_ordered_by_name(): void
    {
        $zed = User::factory()->create(['name' => 'Zed Youssef']);
        $zed->assignRole('member');
        $anwar = User::factory()->create(['name' => 'Anwar Khalil']);
        $anwar->assignRole('member');

        foreach ([$zed, $anwar] as $member) {
            Sanctum::actingAs($member, ['*']);
            $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
            $this->patchJson('/api/v1/member/profile', ['bio' => 'Founder.'])->assertOk();
        }

        $response = $this->getJson('/api/v1/member-directory');

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Anwar Khalil', 'Zed Youssef'], $names);
    }

    public function test_a_member_with_an_empty_profile_still_appears_in_the_directory(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        $this->patchJson('/api/v1/member/consents/public-directory', ['granted' => true])->assertOk();
        $this->patchJson('/api/v1/member/profile', [])->assertOk();

        $response = $this->getJson('/api/v1/member-directory');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }
}
