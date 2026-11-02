<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\Concerns\TravelsOnTheOrganisationsClock;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class EnsureActiveEmployeeTest extends TestCase
{
    use HiresEmployees;
    use TravelsOnTheOrganisationsClock;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth', 'hr.employee'])
            ->get('/test/hr-employee', fn (Request $request) => ['id' => $request->attributes->get('hr_employee')->id]);
    }

    public function test_an_employed_user_passes_with_their_employee_on_the_request(): void
    {
        $user = $this->actingAsWith();
        $employee = $this->hireEmployee(['user_id' => $user->id]);

        $this->getJson('/test/hr-employee')->assertOk()->assertExactJson(['id' => $employee->id]);
    }

    public function test_a_user_without_an_employee_record_is_blocked(): void
    {
        $this->actingAsWith();

        $this->getJson('/test/hr-employee')
            ->assertForbidden()
            ->assertJsonPath('message', __('hr::system.not_an_employee'));
    }

    public function test_an_upcoming_hire_is_blocked_until_their_first_day(): void
    {
        $user = $this->actingAsWith();
        $this->hireEmployee(['user_id' => $user->id, 'hired_on' => now()->addDay()->toDateString()]);

        $this->getJson('/test/hr-employee')->assertForbidden();

        $this->travel(1)->days();
        $this->getJson('/test/hr-employee')->assertOk();
    }

    public function test_access_opens_and_closes_at_the_organisations_midnight(): void
    {
        // 01:00 on 11 March locally, still 10 March in UTC.
        $this->travelToLocal('2026-03-11 01:00');

        $starter = $this->actingAsWith();
        $this->hireEmployee(['user_id' => $starter->id, 'hired_on' => '2026-03-11']);
        $this->getJson('/test/hr-employee')->assertOk();

        $leaver = $this->actingAsWith();
        $employee = $this->hireEmployee(['user_id' => $leaver->id]);
        app(EmploymentLifecycleService::class)->record($employee, EmploymentEventType::Termination, [
            'effective_on' => '2026-03-10',
            'reason' => 'resignation',
        ], null);
        $this->getJson('/test/hr-employee')->assertForbidden();
    }
}
