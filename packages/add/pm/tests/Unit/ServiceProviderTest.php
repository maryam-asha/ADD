<?php

namespace Add\Pm\Tests\Unit;

use Add\Pm\Authorization\Abilities;
use Add\Pm\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_the_package_loads_its_translations_and_lists_its_abilities(): void
    {
        $this->assertSame('Project updated.', __('pm::pm.project_updated', [], 'en'));
        $this->assertSame('تم تحديث المشروع.', __('pm::pm.project_updated', [], 'ar'));

        $this->assertSame(['pm.projects.view', 'pm.projects.manage'], Abilities::all());
    }
}
