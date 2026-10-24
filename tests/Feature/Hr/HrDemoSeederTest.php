<?php

namespace Tests\Feature\Hr;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Models\LeaveRequest;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Recruitment\Models\Application;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\HrDemoSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The HR demo seeder runs through the package's own services, so a rule
 * change that breaks it shows here rather than on someone's machine.
 */
class HrDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_state_once(): void
    {
        Storage::fake('local');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, CurrencySeeder::class]);
        Branch::factory()->create();
        User::factory()->create()->assignRole('admin');

        $this->seed(HrDemoSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['upcoming', 'probation', 'active', 'suspended', 'terminated'],
            Employee::query()->distinct()->pluck('status')->map(fn ($s) => $s->value)->all(),
        );
        $this->assertEqualsCanonicalizing(['pending', 'approved', 'rejected', 'cancelled'], LeaveRequest::query()->distinct()->pluck('status')->map(fn ($s) => $s->value)->all());
        $this->assertSame('paid', PayrollRun::query()->where('kind', 'regular')->sole()->status->value);
        $this->assertEqualsCanonicalizing(['applied', 'screening', 'interview', 'offer', 'hired', 'rejected', 'withdrawn'], Application::query()->distinct()->pluck('stage')->all());

        $count = Employee::query()->count();
        $this->seed(HrDemoSeeder::class);
        $this->assertSame($count, Employee::query()->count());
    }
}
