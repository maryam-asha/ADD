<?php

namespace Tests\Unit\Support\Forms;

use App\Support\Forms\ReservedFieldKeys;
use PHPUnit\Framework\TestCase;

class ReservedFieldKeysTest extends TestCase
{
    public function test_identity_keys_are_reserved_whatever_their_case_or_padding(): void
    {
        foreach (['name', 'Name', 'NAME', ' email ', 'Phone'] as $key) {
            $this->assertTrue(ReservedFieldKeys::isReserved($key), $key);
        }
    }

    public function test_ordinary_keys_are_not_reserved(): void
    {
        foreach (['company_name', 'nickname', 'emails', 'phone_number', 'dietary_needs'] as $key) {
            $this->assertFalse(ReservedFieldKeys::isReserved($key), $key);
        }
    }

    public function test_present_lists_every_reserved_key_in_a_payload(): void
    {
        $found = ReservedFieldKeys::present([
            'dietary_needs' => 'none',
            'Name' => 'Hacker',
            'email' => 'x@y.z',
        ]);

        $this->assertEqualsCanonicalizing(['Name', 'email'], $found);
    }

    public function test_present_is_empty_for_a_clean_payload(): void
    {
        $this->assertSame([], ReservedFieldKeys::present(['dietary_needs' => 'none']));
    }
}
