<?php

namespace Add\Hr\Tests\Unit;

use Add\Hr\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertSame(5, config('hr.documents.link_ttl_minutes'));
    }

    public function test_translations_are_namespaced(): void
    {
        $this->assertNotSame('hr::system.not_an_employee', __('hr::system.not_an_employee'));
    }
}
