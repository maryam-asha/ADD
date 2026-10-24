<?php

namespace Database\Seeders;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Services\WorkCalendar;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccountCreationService;
use Carbon\CarbonImmutable;
use Database\Seeders\HrDemo\SeedsHrLeaveAndTime;
use Database\Seeders\HrDemo\SeedsHrPayAndBenefits;
use Database\Seeders\HrDemo\SeedsHrPeople;
use Database\Seeders\HrDemo\SeedsHrTalent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo data for the HR block (packages/add/hr), so every state shows in the
 * dashboard: an organisation of units, grades and positions; about twenty
 * staff in every employment status (upcoming, probation, active, suspended,
 * in their notice period, terminated, rehired) through every kind of career
 * event; their documents (valid, expiring, expired), checklists, leave
 * (approved, today, upcoming, pending, rejected, cancelled), attendance
 * (on time, late, missing a punch, absent, overtime, a locked month),
 * timesheets, a paid and an in-progress payroll, loans, benefits, a hiring
 * pipeline with a candidate at every stage, a review cycle under way and
 * training with certificates about to expire.
 *
 * Everything goes through the package's own services, as the screens do,
 * so the data obeys every rule. Dates are relative to the day it runs.
 * Staff log in to the dashboard with their email and the password
 * `password` (the HR officer: dana.khatib@staff.demo, role hr_manager).
 *
 * Local and staging only; runs once (skipped when any employee exists):
 * `php artisan db:seed --class=HrDemoSeeder`.
 */
class HrDemoSeeder extends Seeder
{
    use SeedsHrLeaveAndTime;
    use SeedsHrPayAndBenefits;
    use SeedsHrPeople;
    use SeedsHrTalent;

    private CarbonImmutable $today;

    /** The account that approves what an HR administrator decides (payroll, the second loan step). */
    private User $admin;

    /** @var array<string, Employee> the cast, by key */
    private array $staff = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('HrDemoSeeder holds demo people and never runs in production.');
        }

        if (Employee::query()->exists()) {
            $this->command?->warn('HR data already exists; HrDemoSeeder left it as it is.');

            return;
        }

        $this->call(HrPayrollTrialSeeder::class);
        $this->today = CarbonImmutable::today();

        DB::transaction(function () {
            $this->admin = $this->adminAccount();

            foreach (['People', 'Leave', 'Time', 'Timesheets', 'Payroll', 'Benefits', 'Recruitment', 'Performance', 'Training'] as $part) {
                $started = microtime(true);
                $this->{'seed'.$part}();
                $this->command?->line(sprintf('  HR demo: %s (%.1fs)', $part, microtime(true) - $started));
            }
        });

        $this->command?->info('HR demo data seeded: '.Employee::query()->count().' employees.');
    }

    private function adminAccount(): User
    {
        return User::role('admin')->orderBy('id')->first()
            ?? app(AccountCreationService::class)->create([
                'name' => 'مدير النظام (تجريبي)',
                'phone' => '+963944509999',
                'email' => 'hr-admin@staff.demo',
            ], 'admin', 'password');
    }

    private function employee(string $key): Employee
    {
        return $this->staff[$key];
    }

    private function accountOf(string $key): User
    {
        return User::query()->findOrFail($this->staff[$key]->user_id);
    }

    /** The HR officer's account: the actor of everyday HR work. */
    private function hrActor(): int
    {
        return (int) $this->staff['hr_officer']->user_id;
    }

    /**
     * Decide the request's open step as `$by` (an employee key), or as the
     * HR administrator when null.
     */
    private function approve(?int $approvalRequestId, ?string $by = null, bool $reject = false, ?string $comment = null): void
    {
        $request = ApprovalRequest::query()->findOrFail($approvalRequestId);
        $user = $by === null ? $this->admin : $this->accountOf($by);
        $engine = app(ApprovalEngine::class);

        $reject ? $engine->reject($request, $user, $comment) : $engine->approve($request, $user, $comment);
    }

    /** The first working day on or after `$day` (ADD's weekend and holidays skipped). */
    private function workday(CarbonImmutable $day): CarbonImmutable
    {
        $calendar = app(WorkCalendar::class);

        while (! $calendar->isWorkingDay($day)) {
            $day = $day->addDay();
        }

        return $day;
    }

    /**
     * The last `$count` working days before today, oldest first.
     *
     * @return list<CarbonImmutable>
     */
    private function recentWorkdays(int $count): array
    {
        $calendar = app(WorkCalendar::class);
        $days = [];

        for ($day = $this->today->subDay(); count($days) < $count; $day = $day->subDay()) {
            if ($calendar->isWorkingDay($day)) {
                $days[] = $day;
            }
        }

        return array_reverse($days);
    }
}
