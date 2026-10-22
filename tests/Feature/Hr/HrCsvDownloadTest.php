<?php

namespace Tests\Feature\Hr;

use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The dashboard downloads CSVs cross-origin, and a browser hides every
 * response header CORS does not expose — so without Content-Disposition
 * on the list, each file is saved under the dashboard's fallback name.
 */
class HrCsvDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_origin_can_read_the_csv_file_name(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        config(['cors.allowed_origins' => ['https://os.add-sy.com']]);

        Role::create(['name' => 'hr_reports', 'guard_name' => 'web'])->givePermissionTo('hr.reports.view');
        $reader = User::factory()->create();
        $reader->assignRole('hr_reports');
        Sanctum::actingAs($reader, ['*']);

        $response = $this->get('/api/v1/hr/reports/headcount?from=2026-09-01&to=2026-09-30&format=csv', ['Origin' => 'https://os.add-sy.com'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://os.add-sy.com');

        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $exposed = array_map('trim', explode(',', strtolower((string) $response->headers->get('Access-Control-Expose-Headers'))));
        $this->assertContains('content-disposition', $exposed);
    }
}
