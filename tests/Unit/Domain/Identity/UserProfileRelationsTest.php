<?php

namespace Tests\Unit\Domain\Identity;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserPersonalProfile;
use App\Domain\Identity\Models\UserProfessionalProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserProfileRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_has_one_personal_profile(): void
    {
        $user = User::factory()->create();
        UserPersonalProfile::create(['user_id' => $user->id, 'bio' => 'Hello', 'city' => 'Aleppo']);

        $this->assertSame('Hello', $user->personalProfile->bio);
    }

    public function test_a_user_has_one_professional_profile(): void
    {
        $user = User::factory()->create();
        UserProfessionalProfile::create(['user_id' => $user->id, 'job_title' => 'Engineer']);

        $this->assertSame('Engineer', $user->professionalProfile->job_title);
    }

    public function test_user_id_is_unique_on_both_tables(): void
    {
        $user = User::factory()->create();
        UserPersonalProfile::create(['user_id' => $user->id]);

        $this->expectException(QueryException::class);
        UserPersonalProfile::create(['user_id' => $user->id]);
    }
}
