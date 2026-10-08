<?php

namespace Tests\Guards;

use Tests\TestCase;

/**
 * docs/decisions/service-providers.md: the provider surface never borrows
 * an admin or member controller, and every provider controller that takes
 * a company checks it is the provider's own (authorizeCompany — 404
 * otherwise). Written prose is a request, not a guarantee (PRD §9).
 */
class ProviderRoutesStayIsolatedTest extends TestCase
{
    public function test_provider_routes_point_only_at_provider_controllers(): void
    {
        $routes = file_get_contents(base_path('routes/api/v1/provider.php'));

        preg_match_all('/use\s+App\\\\Http\\\\Controllers\\\\Api\\\\V1\\\\(\w+)\\\\/', $routes, $matches);

        $this->assertSame([], array_values(array_diff(array_unique($matches[1]), ['Provider'])),
            'routes/api/v1/provider.php may only use App\Http\Controllers\Api\V1\Provider\* controllers.');
    }

    public function test_every_provider_controller_taking_a_company_authorizes_it(): void
    {
        $violations = [];

        foreach (glob(app_path('Http/Controllers/Api/V1/Provider/*Controller.php')) as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, 'Company $company') && ! str_contains($source, 'authorizeCompany(')) {
                $violations[] = basename($file);
            }
        }

        $this->assertSame([], $violations, 'Provider controllers taking a {company} must call authorizeCompany().');
    }
}
