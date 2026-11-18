<?php

namespace Add\Hr\Tests\Unit;

use Add\Hr\Audit\AuditEntry;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Tests\TestCase;

class HrAuditTest extends TestCase
{
    public function test_record_stores_actor_action_subject_and_properties(): void
    {
        $user = $this->actingAsWith();

        $entry = HrAudit::record('something_sensitive', $user, ['k' => 1]);

        $this->assertInstanceOf(AuditEntry::class, $entry);
        $this->assertDatabaseHas('hr_audit_entries', [
            'id' => $entry->id,
            'actor_id' => $user->id,
            'action' => 'something_sensitive',
            'subject_type' => $user->getMorphClass(),
            'subject_id' => $user->id,
        ]);
        $this->assertSame(1, $entry->fresh()->properties['k']);
    }

    public function test_record_works_without_an_actor_or_subject(): void
    {
        $entry = HrAudit::record('system_job');

        $this->assertNull($entry->fresh()->actor_id);
        $this->assertNull($entry->fresh()->subject_id);
    }
}
