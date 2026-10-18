<?php

namespace Tests\Feature\Hr;

use Add\Hr\Core\Models\OrgUnit;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserPersonalProfile;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * In ADD the person is kept once, on their account (AddPersonStore): name,
 * phone and email on users, personal details on user_personal_profiles, the
 * national ID and bank account encrypted on user_sensitive_profiles. HR
 * keeps only the employment; its workplaces are ADD's branches and its
 * currencies ADD's.
 */
class HrPersonOnAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function hire(User $user, array $overrides = []): int
    {
        return $this->postJson('/api/v1/hr/employees', array_replace_recursive([
            'user_id' => $user->id,
            'hired_on' => now()->subMonth()->toDateString(),
            'contract' => ['type' => 'open_ended'],
            'assignment' => [
                'org_unit_id' => OrgUnit::factory()->create()->id,
                'employment_type' => 'full_time',
                'work_mode' => 'on_site',
            ],
        ], $overrides))->assertCreated()->json('data.id');
    }

    public function test_hiring_an_account_keeps_the_person_on_it(): void
    {
        $user = User::factory()->create(['name' => 'سارة الأحمد', 'phone' => '+963944555001']);
        $branch = Branch::factory()->create();

        $id = $this->hire($user, [
            'date_of_birth' => '1995-04-02',
            'nationality' => 'SY',
            'national_id' => '01020304',
            'bank_account' => 'SY21-0001',
            'assignment' => ['work_location_id' => $branch->id],
        ]);

        $row = DB::table('hr_employees')->find($id);
        $this->assertNull($row->name);
        $this->assertNull($row->phone);
        $this->assertNull($row->national_id);
        $this->assertSame('1995-04-02', UserPersonalProfile::query()->where('user_id', $user->id)->sole()->date_of_birth->toDateString());

        $sensitive = DB::table('user_sensitive_profiles')->where('user_id', $user->id)->sole();
        $this->assertNotSame('01020304', $sensitive->national_id);
        $this->assertSame('01020304', Crypt::decryptString($sensitive->national_id));

        $this->getJson("/api/v1/hr/employees/{$id}")
            ->assertOk()
            ->assertJsonPath('data.name.ar', 'سارة الأحمد')
            ->assertJsonPath('data.phone', '+963944555001')
            ->assertJsonPath('data.work_email', $user->email)
            ->assertJsonPath('data.date_of_birth', '1995-04-02')
            ->assertJsonPath('data.has_national_id', true)
            ->assertJsonPath('data.current_assignment.work_location.id', $branch->id);

        $this->getJson("/api/v1/hr/employees/{$id}/sensitive")
            ->assertOk()
            ->assertJsonPath('data.national_id', '01020304')
            ->assertJsonPath('data.bank_account', 'SY21-0001');
    }

    public function test_the_account_keeps_its_identity_and_hr_edits_the_details(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/hr/employees', ['user_id' => $user->id, 'name' => ['ar' => 'اسم آخر']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', __('hr::core.person_field_on_account'));

        $id = $this->hire($user);

        $this->patchJson("/api/v1/hr/employees/{$id}", ['phone' => '+963944555999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->patchJson("/api/v1/hr/employees/{$id}", ['marital_status' => 'married', 'address' => 'حلب'])->assertOk();

        $profile = UserPersonalProfile::query()->where('user_id', $user->id)->sole();
        $this->assertSame('married', $profile->marital_status);
        $this->assertSame('حلب', $profile->address);
        $this->assertNotSame('+963944555999', $user->fresh()->phone);
    }

    public function test_people_are_found_by_their_account(): void
    {
        $hired = User::factory()->create(['name' => 'ريم الحلبي']);
        $member = User::factory()->create(['name' => 'ريم الشامي']);
        $id = $this->hire($hired);

        $this->getJson('/api/v1/hr/employees?q='.urlencode('ريم'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $accounts = collect($this->getJson('/api/v1/hr/accounts?q='.urlencode('ريم'))->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($id, $accounts[$hired->id]['employee_id']);
        $this->assertNull($accounts[$member->id]['employee_id']);
    }

    public function test_workplaces_are_branches_and_currencies_are_adds(): void
    {
        $branch = Branch::factory()->create();

        $this->getJson('/api/v1/hr/work-locations')->assertOk()->assertJsonPath('data.0.id', $branch->id);
        $this->postJson('/api/v1/hr/work-locations', ['name' => ['ar' => 'موقع']])->assertMethodNotAllowed();

        $this->postJson('/api/v1/hr/payroll-runs', ['year' => 2026, 'month' => 1, 'kind' => 'regular', 'currency' => 'XYZ'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('currency');
    }

    public function test_adds_hr_settings_keep_the_package_defaults_they_do_not_set(): void
    {
        $this->assertSame([5, 6], config('hr.leave.weekend_days'));
        $this->assertSame(366, config('hr.leave.max_request_days'));
        $this->assertSame(7, config('hr.timesheets.week_starts_on'));
        $this->assertSame(0.5, config('hr.timesheets.increment_hours'));
        $this->assertSame(60, config('hr.attendance.duplicate_seconds'));
    }
}
