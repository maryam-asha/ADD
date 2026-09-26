<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Forms live in forms / form_fields / form_submissions / form_answers for
 * every owner (docs/decisions/unified-forms.md). The per-domain tables they
 * replaced stay in the schema only as a rollback source until the release
 * that drops them; nothing may read or write them in the meantime, or data
 * would quietly split across two homes again.
 *
 * The backfill migration and its test are the only legitimate readers, and
 * sit outside the scanned paths. Delete the table half of this guard with
 * the migration that drops the tables.
 */
class LegacyFormTablesAreUnreferencedTest extends TestCase
{
    use ScansSourceFiles;

    private const LEGACY = [
        'service_type_fields',
        'service_request_values',
        'event_registration_fields',
        'event_registration_field_values',
        'ServiceTypeField',
        'ServiceRequestValue',
        'EventRegistrationField',
        'EventRegistrationFieldValue',
    ];

    private const SCANNED = ['app', 'routes', 'database/seeders', 'database/factories'];

    public function test_no_code_touches_the_per_domain_form_tables(): void
    {
        $hits = [];

        foreach (self::SCANNED as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path => $contents) {
                foreach (self::LEGACY as $name) {
                    if (preg_match('/\b'.$name.'\b/', $contents)) {
                        $hits[] = "{$path} references {$name}";
                    }
                }
            }
        }

        $this->assertSame([], $hits, "Use the unified form models instead:\n".implode("\n", $hits));
    }
}
