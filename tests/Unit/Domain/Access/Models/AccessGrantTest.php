<?php

namespace Tests\Unit\Domain\Access\Models;

use App\Domain\Access\Models\AccessGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_without_a_grantee_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AccessGrant::factory()->make(['grantee_type' => null])->save();
    }
}
