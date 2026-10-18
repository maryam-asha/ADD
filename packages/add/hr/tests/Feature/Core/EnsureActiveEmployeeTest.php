<?php

namespace Add\Hr\Tests\Feature\Core;

use Add\Hr\Tests\Concerns\HiresEmployees;
use Add\Hr\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class EnsureActiveEmployeeTest extends TestCase
{
    use HiresEmployees;

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
}
