<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Architecture convention (backend build plan §A.4): from this point on,
 * enum-shaped columns are `string`, cast to a PHP 8.2 backed enum on the
 * model — not a MySQL `ENUM(...)` column. Three enums the new documents
 * introduce are explicitly unresolved (partner_type, community category,
 * QR scope_type), and altering a MySQL ENUM is a locking, full-table
 * `ALTER`; a cast is a one-line code change.
 *
 * The nine tables below predate this convention and are grandfathered
 * rather than rewritten (db-migrator's own rule: schema changes are
 * additive, not retroactive). Every migration written from Phase 1 onward
 * must not appear in this list.
 */
class NoNewMysqlEnumColumnsTest extends TestCase
{
    use ScansSourceFiles;

    /** @var list<string> pre-existing migrations allowed to keep MySQL ENUM columns */
    private const LEGACY_ALLOWLIST = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/2026_07_29_113330_create_spaces_table.php',
        'database/migrations/2026_07_29_113331_create_devices_table.php',
        'database/migrations/2026_07_29_113333_create_device_capabilities_table.php',
        'database/migrations/2026_07_29_113337_create_otp_verifications_table.php',
        'database/migrations/2026_07_29_113338_create_notification_logs_table.php',
        'database/migrations/2026_07_30_145510_create_events_table.php',
        'database/migrations/2026_07_30_145514_create_community_members_table.php',
        'database/migrations/2026_07_30_145516_create_partners_table.php',
    ];

    public function test_no_migration_outside_the_legacy_allowlist_uses_mysql_enum(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(database_path('migrations')) as $path => $contents) {
            if (in_array($path, self::LEGACY_ALLOWLIST, true)) {
                continue;
            }

            if (preg_match('/->\s*enum\s*\(/', $contents)) {
                $violations[] = "{$path} uses ->enum(...) — use string + PHP backed enum cast instead";
            }
        }

        $this->assertSame([], $violations, "New tables use string columns + PHP enum casts, not MySQL ENUM (build plan §A.4):\n".implode("\n", $violations));
    }
}
