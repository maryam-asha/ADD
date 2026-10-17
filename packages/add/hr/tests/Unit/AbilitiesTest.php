<?php

namespace Add\Hr\Tests\Unit;

use Add\Hr\Authorization\Abilities;
use PHPUnit\Framework\TestCase;

class AbilitiesTest extends TestCase
{
    public function test_all_lists_every_ability_once(): void
    {
        $all = Abilities::all();

        $this->assertCount(20, $all);
        $this->assertSame($all, array_values(array_unique($all)));
    }

    public function test_names_follow_hr_module_action(): void
    {
        foreach (Abilities::all() as $ability) {
            $this->assertMatchesRegularExpression('/^hr\.[a-z_]+\.[a-z_]+$/', $ability);
        }
    }
}
