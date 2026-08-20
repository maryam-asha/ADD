<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The authenticated counterpart to PasswordResetTest: here the member proves
 * identity with their current password on a session that already exists, so
 * unlike a reset — which has no session or password to trust and cuts every
 * one of them — this only cuts every *other* session. The one making the
 * request keeps working.
 */
class ChangePasswordTest extends IdentityTestCase
{
    private const PHONE = '+963912345678';

    private const OLD_PASSWORD = 'old-password';

    private const NEW_PASSWORD = 'brand-new-password';

    private function member(): User
    {
        $user = User::factory()->create([
            'phone' => self::PHONE,
            'password' => self::OLD_PASSWORD,
        ]);

        $user->assignRole('member');

        return $user;
    }

    public function test_a_member_changes_their_password_with_the_correct_current_password(): void
    {
        $member = $this->member();
        $token = $member->createToken('member-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/member/account/password', [
                'current_password' => self::OLD_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $response->assertOk();
        $response->assertJson(['message' => __('api.auth.password_changed')]);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $member->fresh()->password));
    }

    public function test_the_wrong_current_password_is_rejected_and_the_password_is_unchanged(): void
    {
        $member = $this->member();
        $token = $member->createToken('member-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/member/account/password', [
                'current_password' => 'not-the-right-password',
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $response->assertStatus(422);
        $response->assertJson(['message' => __('api.auth.current_password_incorrect')]);

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $member->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $member = $this->member();
        $token = $member->createToken('member-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/member/account/password', [
                'current_password' => self::OLD_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => 'mismatched',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_the_new_password_must_meet_the_minimum_length(): void
    {
        $member = $this->member();
        $token = $member->createToken('member-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/member/account/password', [
                'current_password' => self::OLD_PASSWORD,
                'password' => 'short12',
                'password_confirmation' => 'short12',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    /**
     * The point of this whole feature: the session that just proved identity
     * survives, while every other session held by the same member dies.
     */
    public function test_the_current_session_survives_but_every_other_session_is_cut(): void
    {
        $member = $this->member();
        $currentToken = $member->createToken('member-app')->plainTextToken;
        $otherToken = $member->createToken('member-app')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->patchJson('/api/v1/member/account/password', [
                'current_password' => self::OLD_PASSWORD,
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ]);

        $response->assertOk();

        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$currentToken}")
            ->getJson('/api/v1/member/profile')
            ->assertOk();

        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$otherToken}")
            ->getJson('/api/v1/member/profile')
            ->assertStatus(401);
    }
}
