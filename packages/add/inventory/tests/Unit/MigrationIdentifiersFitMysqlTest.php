<?php

namespace Add\Inventory\Tests\Unit;

use Add\Inventory\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * MySQL refuses identifiers longer than 64 characters; SQLite, which the
 * tests run on, accepts any length. Laravel derives index names from the
 * table and its columns, so a long one can pass every test and still fail
 * its first real migration.
 */
class MigrationIdentifiersFitMysqlTest extends TestCase
{
    public function test_every_index_name_fits_mysql(): void
    {
        $names = collect(DB::select("select name from sqlite_master where type = 'index' and name like 'inv\\_%' escape '\\'"))
            ->pluck('name');

        $this->assertNotEmpty($names);
        $this->assertSame([], $names->filter(fn (string $name) => strlen($name) > 64)->values()->all(), 'Give these indexes an explicit shorter name.');
    }
}
