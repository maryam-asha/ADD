<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Models\UserProfessionalProfile;
use App\Domain\Identity\Services\ProfileCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileCompletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const WEIGHTS = [
        'avatar_url' => 15,
        'job_title' => 10,
        'bio' => 10,
        'city' => 10,
        'industry' => 7,
        'company_name' => 6,
        'linkedin_url' => 5,
        'instagram_url' => 4,
        'behance_url' => 3,
        'gender' => 3,
        'website_url' => 2,
    ];

    public function test_a_freshly_registered_member_scores_exactly_25(): void
    {
        $user = User::factory()->create();

        $service = new ProfileCompletionService;

        $this->assertSame(25, $service->score($user));
        $this->assertCount(11, $service->missingFields($user));
    }

    public function test_each_personal_field_contributes_its_own_weight_in_isolation(): void
    {
        $service = new ProfileCompletionService;

        foreach ([
            'avatar_url' => ['avatar_url' => 'https://example.com/a.png'],
            'bio' => ['bio' => 'Hello'],
            'city' => ['city' => 'Aleppo'],
            'gender' => ['gender' => 'male'],
        ] as $field => $attributes) {
            $user = User::factory()->create();
            UserPersonalProfile::create(['user_id' => $user->id] + $attributes);

            $this->assertSame(
                25 + self::WEIGHTS[$field],
                $service->score($user),
                "Field [{$field}] did not contribute its documented weight in isolation."
            );
        }
    }

    public function test_each_professional_field_contributes_its_own_weight_in_isolation(): void
    {
        $service = new ProfileCompletionService;

        foreach ([
            'job_title' => ['job_title' => 'Engineer'],
            'company_name' => ['company_name' => 'ACME'],
            'industry' => ['industry' => 'Software'],
            'linkedin_url' => ['linkedin_url' => 'https://linkedin.com/in/x'],
            'instagram_url' => ['instagram_url' => 'https://instagram.com/x'],
            'behance_url' => ['behance_url' => 'https://behance.net/x'],
            'website_url' => ['website_url' => 'https://example.com'],
        ] as $field => $attributes) {
            $user = User::factory()->create();
            UserProfessionalProfile::create(['user_id' => $user->id] + $attributes);

            $this->assertSame(
                25 + self::WEIGHTS[$field],
                $service->score($user),
                "Field [{$field}] did not contribute its documented weight in isolation."
            );
        }
    }

    public function test_filling_every_field_scores_exactly_100_with_no_fields_missing(): void
    {
        $user = User::factory()->create();
        UserPersonalProfile::create([
            'user_id' => $user->id,
            'bio' => 'Hello',
            'city' => 'Aleppo',
            'avatar_url' => 'https://example.com/a.png',
            'gender' => 'male',
        ]);
        UserProfessionalProfile::create([
            'user_id' => $user->id,
            'job_title' => 'Engineer',
            'company_name' => 'ACME',
            'industry' => 'Software',
            'linkedin_url' => 'https://linkedin.com/in/x',
            'instagram_url' => 'https://instagram.com/x',
            'behance_url' => 'https://behance.net/x',
            'website_url' => 'https://example.com',
        ]);

        $service = new ProfileCompletionService;

        $this->assertSame(100, $service->score($user));
        $this->assertSame([], $service->missingFields($user));
    }

    public function test_a_partial_profile_reports_exactly_the_fields_still_missing(): void
    {
        $user = User::factory()->create();
        UserPersonalProfile::create([
            'user_id' => $user->id,
            'bio' => 'Hello',
            'city' => 'Aleppo',
        ]);

        $service = new ProfileCompletionService;

        $this->assertSame(
            ['avatar_url', 'job_title', 'industry', 'company_name', 'linkedin_url', 'instagram_url', 'behance_url', 'gender', 'website_url'],
            $service->missingFields($user)
        );
    }

    public function test_score_is_computed_on_read_and_reflects_a_field_change_immediately(): void
    {
        $user = User::factory()->create();
        $profile = UserPersonalProfile::create(['user_id' => $user->id]);

        $service = new ProfileCompletionService;
        $this->assertSame(25, $service->score($user->fresh()));

        $profile->update(['bio' => 'Hello']);

        $this->assertSame(35, $service->score($user->fresh()));
    }
}
