<?php

namespace Tests\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRD decision #2: Floor and Zone are classification/display only — no
 * booking or access logic, ever ("لا يظهران في مسار الحجز إلا كتصفية عرض").
 * Unlike the other guards in this suite, this one is schema-shape only:
 * the absence of a `status` column and of any device/lock/booking foreign
 * key on these two tables *is* the guarantee. If either column shows up
 * later, decision #2 has been quietly walked back.
 */
class SpatialHierarchyGuardTest extends TestCase
{
    use RefreshDatabase;

    private const NO_ESCALATION_TABLES = ['floors', 'zones'];

    private const FORBIDDEN_COLUMNS = [
        'status', 'status_reason', 'status_from', 'status_until',
        'device_id', 'lock_id', 'booking_id', 'passcode_id',
    ];

    public function test_floors_and_zones_carry_no_status_or_access_columns(): void
    {
        $violations = [];

        foreach (self::NO_ESCALATION_TABLES as $table) {
            foreach (self::FORBIDDEN_COLUMNS as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $violations[] = "{$table}.{$column}";
                }
            }
        }

        $this->assertSame([], $violations, "Decision #2 — Floor/Zone carry no operational status and no booking/access FK:\n".implode("\n", $violations));
    }
}
