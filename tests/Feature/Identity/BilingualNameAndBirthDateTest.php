<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithOtp;

/**
 * Sign-up takes one name and stores it in the language it is written in; the
 * profile edits both languages and the date of birth; `name` in a response
 * is the one in the request's language.
 */
class BilingualNameAndBirthDateTest extends IdentityTestCase
{
    use InteractsWithOtp;

    private const PHONE = '+963912345678';

    private function register(string $name): User
    {
        $this->fakeOtpProvider();

        $this->startRegistration([
            'phone' => self::PHONE,
            'name' => $name,
            'password' => 'correct-horse',
            'password_confirmation' => 'correct-horse',
            'privacy_policy_accepted' => true,
        ]);

        $this->postJson('/api/v1/auth/register/verify', [
            'phone' => self::PHONE,
            'code' => $this->otpProvider->lastCodeFor(self::PHONE),
        ])->assertOk();

        return User::query()->where('phone', self::PHONE)->firstOrFail();
    }

    private function member(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole('member');
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_an_arabic_sign_up_name_is_stored_as_the_arabic_name(): void
    {
        $user = $this->register('مريم عشا');

        $this->assertSame('مريم عشا', $user->name_ar);
        $this->assertNull($user->name_en);
    }

    public function test_a_latin_sign_up_name_is_stored_as_the_english_name(): void
    {
        $user = $this->register('Maryam Asha');

        $this->assertSame('Maryam Asha', $user->name_en);
        $this->assertNull($user->name_ar);
    }

    public function test_the_profile_edits_both_names_and_the_date_of_birth(): void
    {
        $user = $this->member(['name' => 'مريم']);

        $this->patchJson('/api/v1/member/profile', [
            'name_ar' => 'مريم عشا',
            'name_en' => 'Maryam Asha',
            'date_of_birth' => '1995-04-12',
        ])->assertOk();

        $user->refresh();
        $this->assertSame('مريم عشا', $user->name_ar);
        $this->assertSame('Maryam Asha', $user->name_en);
        $this->assertSame('1995-04-12', $user->personalProfile->date_of_birth->toDateString());

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/profile')
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Maryam Asha')
            ->assertJsonPath('data.user.name_ar', 'مريم عشا')
            ->assertJsonPath('data.user.name_en', 'Maryam Asha')
            ->assertJsonPath('data.user.personal.date_of_birth', '1995-04-12');

        $this->withHeader('lang', 'ar')->getJson('/api/v1/member/profile')
            ->assertJsonPath('data.user.name', 'مريم عشا');
    }

    public function test_the_name_falls_back_to_the_other_language(): void
    {
        $this->member(['name' => 'Maryam Asha']);

        $this->withHeader('lang', 'ar')->getJson('/api/v1/member/profile')
            ->assertJsonPath('data.user.name', 'Maryam Asha')
            ->assertJsonPath('data.user.name_ar', null);
    }

    public function test_the_last_name_left_cannot_be_cleared(): void
    {
        $user = $this->member(['name' => 'Maryam Asha']);

        $this->patchJson('/api/v1/member/profile', ['name_en' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name_en');

        $this->assertSame('Maryam Asha', $user->fresh()->name_en);
    }

    public function test_one_name_can_be_cleared_while_the_other_is_kept(): void
    {
        $user = $this->member(['name' => 'Maryam Asha']);
        $this->patchJson('/api/v1/member/profile', ['name_ar' => 'مريم'])->assertOk();

        $this->patchJson('/api/v1/member/profile', ['name_en' => null])->assertOk();

        $user->refresh();
        $this->assertNull($user->name_en);
        $this->assertSame('مريم', $user->getAttributes()['name']);
    }

    public function test_a_future_date_of_birth_is_refused(): void
    {
        $this->member();

        $this->patchJson('/api/v1/member/profile', ['date_of_birth' => now()->addDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_birth');
    }

    public function test_search_finds_a_member_by_either_name(): void
    {
        $user = User::factory()->create(['name' => 'Maryam Asha']);
        $user->update(['name_ar' => 'مريم عشا']);

        $this->assertTrue(User::query()->nameLike('مريم')->whereKey($user->id)->exists());
        $this->assertTrue(User::query()->nameLike('Maryam')->whereKey($user->id)->exists());
    }
}
