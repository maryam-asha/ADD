<?php

namespace Tests\Feature\Identity;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;

/**
 * Login hands back, inside `user`, the branch the member belongs to: their
 * home branch, else the default one.
 */
class AuthResponseBranchTest extends IdentityTestCase
{
    private const PHONE = '+963912345678';

    private const PASSWORD = 'correct-horse';

    private function member(): User
    {
        $user = User::factory()->create(['phone' => self::PHONE, 'password' => self::PASSWORD]);
        $user->assignRole('member');

        return $user;
    }

    private function login()
    {
        return $this->postJson('/api/v1/auth/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_login_returns_the_members_home_branch(): void
    {
        Branch::factory()->create(['is_default' => true]);
        $home = Branch::factory()->create();
        $this->member()->branches()->attach($home->id, ['is_home_branch' => true]);

        $this->login()
            ->assertJsonPath('user.branch_id', $home->id)
            ->assertJsonMissingPath('user.branch');
    }

    public function test_login_falls_back_to_the_default_branch(): void
    {
        $default = Branch::factory()->create(['is_default' => true]);
        Branch::factory()->create();
        $this->member();

        $this->login()->assertJsonPath('user.branch_id', $default->id);
    }

    public function test_branch_is_null_when_no_branch_exists(): void
    {
        Branch::query()->delete();
        $this->member();

        $this->login()->assertJsonPath('user.branch_id', null);
    }
}
