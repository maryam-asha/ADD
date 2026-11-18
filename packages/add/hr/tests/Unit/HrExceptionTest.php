<?php

namespace Add\Hr\Tests\Unit;

use Add\Hr\Support\HrException;
use Add\Hr\Tests\TestCase;

class HrExceptionTest extends TestCase
{
    public function test_fail_throws_an_http_exception_with_a_translated_message(): void
    {
        app()->setLocale('ar');

        try {
            HrException::fail('hr::system.forbidden', [], 403);
            $this->fail('HrException::fail() returned instead of throwing.');
        } catch (HrException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('لا تملك صلاحية تنفيذ هذا الإجراء.', $e->getMessage());
        }
    }

    public function test_status_defaults_to_422(): void
    {
        try {
            HrException::fail('hr::system.forbidden');
        } catch (HrException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }
}
