<?php

namespace Tests\Unit\Domain\Foundation;

use App\Domain\Foundation\Models\Branch;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BranchDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_only_one_branch_can_be_default_at_a_time(): void
    {
        $branch1 = Branch::factory()->create(['is_default' => true]);
        $branch2 = Branch::factory()->create(['is_default' => false]);

        $this->assertTrue($branch1->fresh()->is_default);
        $this->assertFalse($branch2->fresh()->is_default);

        // Mark branch2 as default
        $branch2->update(['is_default' => true]);

        $this->assertFalse($branch1->fresh()->is_default, 'branch1 must automatically be unmarked as default');
        $this->assertTrue($branch2->fresh()->is_default, 'branch2 must now be default');
    }

    public function test_default_id_helper_returns_default_branch_and_caches_it(): void
    {
        $branch = Branch::factory()->create(['is_default' => true]);

        $this->assertSame($branch->id, Branch::defaultId());
        $this->assertSame($branch->id, Cache::get('default_branch_id'));

        // When another branch becomes default, cache is invalidated
        $branch2 = Branch::factory()->create(['is_default' => true]);

        $this->assertSame($branch2->id, Branch::defaultId());
        $this->assertSame($branch2->id, Cache::get('default_branch_id'));
    }

    public function test_deleting_default_branch_reassigns_default_and_clears_cache(): void
    {
        $branch1 = Branch::factory()->create(['is_default' => true]);
        $branch2 = Branch::factory()->create(['is_default' => false]);

        $this->assertSame($branch1->id, Branch::defaultId());

        $branch1->delete();

        // branch2 becomes default
        $this->assertTrue($branch2->fresh()->is_default);
        $this->assertSame($branch2->id, Branch::defaultId());
    }
}
