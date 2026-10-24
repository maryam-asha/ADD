<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Inventory is a package meant for other Laravel projects too
 * (packages/add/inventory). Everything ADD-specific — its places, its
 * people, its notifications — lives in ADD's adapters (app/Domain/Inventory)
 * behind the package's contracts; the package itself never names this
 * application's namespace, nor the HR package, which another host may not
 * have. Written prose is a request, not a guarantee (PRD §9).
 */
class InventoryPackageStaysHostAgnosticTest extends TestCase
{
    use ScansSourceFiles;

    public function test_package_never_references_the_host_app_or_the_hr_package(): void
    {
        $violations = [];

        foreach (['src', 'routes', 'database', 'config'] as $dir) {
            foreach ($this->phpFilesIn(base_path("packages/add/inventory/{$dir}")) as $path => $source) {
                // config/inventory.php names Laravel's default user model as
                // the out-of-the-box value; every host overrides it.
                $source = str_replace("'App\\\\Models\\\\User'", '', $source);

                if (preg_match('/\bApp\\\\|\bAdd\\\\Hr\\\\/', $source)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, 'packages/add/inventory must not reference App\\ or Add\\Hr\\; put host code in app/Domain/Inventory adapters.');
    }
}
