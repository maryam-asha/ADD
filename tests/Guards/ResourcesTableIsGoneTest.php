<?php

namespace Tests\Guards;

use Illuminate\Support\Facades\Schema;
use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * `resources` mixed what a space offers (now amenities, an admin-managed
 * catalog) with the equipment in it (now inventory assets,
 * packages/add/inventory); 2026_10_24_300000 moved its rows and
 * 2026_10_24_300100 dropped it. Nothing may bring the old model back
 * alongside the two that replaced it.
 */
class ResourcesTableIsGoneTest extends TestCase
{
    use ScansSourceFiles;

    public function test_the_table_and_its_model_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('resources'));

        $violations = [];

        foreach ([app_path(), base_path('routes'), database_path('seeders'), database_path('factories')] as $dir) {
            foreach ($this->phpFilesIn($dir) as $path => $source) {
                if (preg_match('/Foundation\\\\(Models\\\\Resource|Enums\\\\ResourceCategory)\b|table\([\'"]resources[\'"]\)|[\'"]resources\/\{/', $source)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, 'Equipment is an inventory asset and what a space offers an amenity; resources is gone.');
    }
}
