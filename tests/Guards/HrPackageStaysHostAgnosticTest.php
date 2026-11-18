<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * The HR block is a package meant for other Laravel projects too
 * (packages/add/hr). Everything ADD-specific lives in ADD's adapters
 * (app/Domain/Hr) behind the package's contracts; the package itself never
 * names this application's namespace. Written prose is a request, not a
 * guarantee (PRD §9).
 */
class HrPackageStaysHostAgnosticTest extends TestCase
{
    use ScansSourceFiles;

    public function test_package_never_references_the_host_app_namespace(): void
    {
        $violations = [];

        foreach (['src', 'routes', 'database', 'config'] as $dir) {
            foreach ($this->phpFilesIn(base_path("packages/add/hr/{$dir}")) as $path => $source) {
                // config/hr.php names Laravel's default user model as the
                // out-of-the-box value; every host overrides it.
                $source = str_replace("'App\\\\Models\\\\User'", '', $source);

                if (preg_match('/\bApp\\\\/', $source)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, 'packages/add/hr must not reference the App\\ namespace; put host code in app/Domain/Hr adapters.');
    }
}
