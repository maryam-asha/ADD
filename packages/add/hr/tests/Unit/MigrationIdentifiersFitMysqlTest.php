<?php

namespace Add\Hr\Tests\Unit;

use Add\Hr\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * MySQL refuses identifiers longer than 64 characters; SQLite, which the
 * tests run on, accepts any length. Laravel derives index names from the
 * table and its columns, so a long table with several columns can pass every
 * test and still fail its first real migration.
 */
class MigrationIdentifiersFitMysqlTest extends TestCase
{
    public function test_every_index_name_fits_mysql(): void
    {
        $tooLong = collect(DB::select("select name from sqlite_master where type = 'index' and name like 'hr\\_%' escape '\\'"))
            ->pluck('name')
            ->filter(fn (string $name) => strlen($name) > 64)
            ->values()
            ->all();

        $this->assertSame([], $tooLong, 'Give these indexes an explicit shorter name.');
    }
}
