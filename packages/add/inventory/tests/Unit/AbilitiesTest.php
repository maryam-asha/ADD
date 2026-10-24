<?php

namespace Add\Inventory\Tests\Unit;

use Add\Inventory\Authorization\Abilities;
use PHPUnit\Framework\TestCase;

class AbilitiesTest extends TestCase
{
    public function test_all_lists_every_ability_once(): void
    {
        $all = Abilities::all();

        $this->assertCount(13, $all);
        $this->assertSame($all, array_values(array_unique($all)));
    }

    public function test_names_follow_inventory_module_action(): void
    {
        foreach (Abilities::all() as $ability) {
            $this->assertMatchesRegularExpression('/^inventory\.[a-z_]+\.[a-z_]+$/', $ability);
        }
    }
}
