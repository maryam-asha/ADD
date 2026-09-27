<?php

namespace Tests\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Forms live in forms / form_fields / form_submissions / form_answers for
 * every owner (docs/decisions/unified-forms.md). The per-domain tables they
 * replaced were copied over by the backfill and dropped straight after it
 * (2026_09_27_100006); none of them, nor a model for one, may come back —
 * a new owner gets a form through HasForms, never through tables of its own.
 *
 * The two migrations and LegacyFormBackfillTest are the only legitimate
 * mentions, and sit outside the scanned paths.
 */
class LegacyFormTablesAreUnreferencedTest extends TestCase
{
    use RefreshDatabase, ScansSourceFiles;

    private const LEGACY_TABLES = [
        'service_type_fields',
        'service_request_values',
        'event_registration_fields',
        'event_registration_field_values',
    ];

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

    public function test_the_per_domain_form_tables_are_gone(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should have been dropped by 2026_09_27_100006.");
        }
    }

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
