<?php

namespace Database\Seeders\HrDemo;

use Add\Hr\Approvals\Models\ApprovalDelegation;
use Add\Hr\Approvals\Models\ApprovalFlow;
use Add\Hr\Core\Enums\EmploymentEventType;
use Add\Hr\Core\Models\ChecklistTask;
use Add\Hr\Core\Models\ChecklistTemplate;
use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Models\EmployeeContact;
use Add\Hr\Core\Models\EmployeeDependent;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Add\Hr\Core\Services\DocumentVault;
use Add\Hr\Core\Services\EmploymentLifecycleService;
use Add\Hr\Payroll\Services\LoanService;
use Add\Hr\Time\Models\ShiftTemplate;
use Add\Hr\Time\Models\WorkPattern;
use Add\Hr\Training\Services\LearningRequestService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Services\AccountCreationService;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The organisation and its people: units, grades, positions, shifts and work
 * patterns, document types, checklists and approval flows, then the staff —
 * one in each employment situation — with their career events, contacts,
 * documents and checklist progress.
 */
trait SeedsHrPeople
{
    /** @var array<string, OrgUnit> */
    private array $units = [];

    /** @var array<string, JobGrade> */
    private array $grades = [];

    /** @var array<string, Position> */
    private array $positions = [];

    /** @var array<string, WorkPattern> */
    private array $patterns = [];

    /** @var array<string, ShiftTemplate> */
    private array $shifts = [];

    /** @var array<string, DocumentType> */
    private array $documentTypes = [];

    private ?int $branchId = null;

    private function seedPeople(): void
    {
        $this->branchId = Branch::query()->orderBy('id')->value('id');

        $this->seedOrganisation();
        $this->seedSchedules();
        $this->seedHrSettings();
        $this->seedStaff();
        $this->seedCareers();

        // The events above changed dates and statuses behind these copies.
        foreach ($this->staff as $employee) {
            $employee->refresh();
        }

        $this->seedPersonalFiles();
    }

    private function seedOrganisation(): void
    {
        $unit = fn (string $code, string $ar, string $en, ?string $parent = null, bool $active = true) => $this->units[$code] = OrgUnit::query()->create([
            'code' => $code,
            'name' => ['ar' => $ar, 'en' => $en],
            'parent_id' => $parent ? $this->units[$parent]->id : null,
            'is_active' => $active,
        ]);

        $unit('GM', 'الإدارة العامة', 'General management');
        $unit('OPS', 'العمليات', 'Operations', 'GM');
        $unit('RCP', 'الاستقبال', 'Reception', 'OPS');
        $unit('CAFE', 'المقهى', 'Café', 'OPS');
        $unit('SALES', 'المبيعات والشراكات', 'Sales and partnerships', 'GM');
        $unit('FIN', 'المالية والموارد البشرية', 'Finance and HR', 'GM');
        $unit('LAB', 'مختبر ADD', 'ADD Lab', 'GM');
        $unit('EVT', 'الفعاليات (موقوفة)', 'Events (closed)', 'GM', active: false);

        foreach ([['G1', 'مبتدئ', 'Entry', 1], ['G2', 'أخصائي', 'Specialist', 2], ['G3', 'أخصائي أول', 'Senior specialist', 3], ['G4', 'مدير', 'Manager', 4], ['G5', 'إدارة عليا', 'Executive', 5]] as [$code, $ar, $en, $rank]) {
            $this->grades[$code] = JobGrade::query()->create(['code' => $code, 'name' => ['ar' => $ar, 'en' => $en], 'rank' => $rank]);
        }

        $position = fn (string $code, string $unit, string $grade, string $ar, string $en, int $headcount = 1, bool $active = true) => $this->positions[$code] = Position::query()->create([
            'code' => $code,
            'org_unit_id' => $this->units[$unit]->id,
            'job_grade_id' => $this->grades[$grade]->id,
            'title' => ['ar' => $ar, 'en' => $en],
            'headcount' => $headcount,
            'is_active' => $active,
        ]);

        $position('GM-01', 'GM', 'G5', 'المدير العام', 'General manager');
        $position('OPS-MGR', 'OPS', 'G4', 'مدير العمليات', 'Operations manager');
        $position('OPS-AGT', 'OPS', 'G2', 'منسق عمليات', 'Operations coordinator', 3);
        $position('RCP-LEAD', 'RCP', 'G3', 'مشرف الاستقبال', 'Reception lead');
        $position('RCP-AGT', 'RCP', 'G2', 'موظف استقبال', 'Receptionist', 4);
        $position('BARISTA', 'CAFE', 'G1', 'باريستا', 'Barista', 6);
        $position('SALES-MGR', 'SALES', 'G4', 'مدير المبيعات', 'Sales manager');
        $position('SALES-REP', 'SALES', 'G3', 'مندوب مبيعات', 'Sales representative', 2);
        $position('ACCOUNTANT', 'FIN', 'G3', 'محاسب', 'Accountant');
        $position('HR-OFFICER', 'FIN', 'G3', 'مسؤول موارد بشرية', 'HR officer');
        $position('DEV', 'LAB', 'G3', 'مطوّر برمجيات', 'Software developer', 2);
        $position('INTERN', 'LAB', 'G1', 'متدرّب', 'Intern', 2);
        $position('EVT-COORD', 'EVT', 'G2', 'منسق فعاليات', 'Events coordinator', 1, active: false);
    }

    private function seedSchedules(): void
    {
        $shift = fn (string $code, string $ar, string $en, string $starts, string $ends, int $break = 30) => $this->shifts[$code] = ShiftTemplate::query()->create([
            'code' => $code, 'name' => ['ar' => $ar, 'en' => $en], 'starts_at' => $starts, 'ends_at' => $ends, 'break_minutes' => $break, 'is_active' => true,
        ]);

        $shift('morning', 'صباحي', 'Morning', '08:00', '16:00');
        $shift('evening', 'مسائي', 'Evening', '14:00', '22:00');
        $shift('night', 'ليلي', 'Night', '22:00', '06:00', 45);

        // ADD works Sunday to Thursday (ISO 7, 1-4).
        $week = fn (?int $shiftId) => collect(range(1, 7))->mapWithKeys(fn (int $day) => [(string) $day => in_array($day, [7, 1, 2, 3, 4], true) ? $shiftId : null])->all();

        $this->patterns['office'] = WorkPattern::query()->create([
            'code' => 'office-week', 'name' => ['ar' => 'دوام مكتبي', 'en' => 'Office week'], 'kind' => 'fixed', 'weekly' => $week($this->shifts['morning']->id), 'is_active' => true,
        ]);
        $this->patterns['roster'] = WorkPattern::query()->create([
            'code' => 'reception-roster', 'name' => ['ar' => 'جدول الاستقبال', 'en' => 'Reception roster'], 'kind' => 'roster', 'is_active' => true,
        ]);
        $this->patterns['flexible'] = WorkPattern::query()->create([
            'code' => 'flex-40', 'name' => ['ar' => 'دوام مرن 40 ساعة', 'en' => 'Flexible, 40 hours'], 'kind' => 'flexible', 'weekly_hours' => 40, 'is_active' => true,
        ]);
    }

    private function seedHrSettings(): void
    {
        $type = fn (string $code, string $ar, string $en, bool $required, bool $expiry, ?array $contracts = null) => $this->documentTypes[$code] = DocumentType::query()->create([
            'code' => $code, 'name' => ['ar' => $ar, 'en' => $en], 'is_required' => $required, 'has_expiry' => $expiry, 'contract_types' => $contracts, 'is_active' => true,
        ]);

        $type('national-id', 'صورة الهوية الشخصية', 'National ID copy', required: true, expiry: true);
        $type('contract', 'نسخة العقد الموقّع', 'Signed contract', required: true, expiry: false);
        $type('health', 'الشهادة الصحية', 'Health certificate', required: false, expiry: true);
        $type('internship-letter', 'كتاب التدريب الجامعي', 'University internship letter', required: true, expiry: false, contracts: ['internship']);

        $item = fn (string $ar, string $en, int $offset) => ['title' => ['ar' => $ar, 'en' => $en], 'due_offset_days' => $offset];

        ChecklistTemplate::query()->create(['kind' => 'onboarding', 'name' => ['ar' => 'استقبال موظف جديد', 'en' => 'New starter'], 'is_active' => true, 'items' => [
            $item('تجهيز البريد وحساب الداشبورد', 'Email and dashboard account', -2),
            $item('إصدار بطاقة الدوام', 'Issue the staff card', 0),
            $item('جولة تعريفية في الحي', 'Tour of the district', 1),
            $item('تسليم نسخة العقد الموقّعة', 'Signed contract on file', 3),
            $item('مراجعة نهاية الأسبوع الأول', 'End of first week check-in', 7),
        ]]);
        ChecklistTemplate::query()->create(['kind' => 'offboarding', 'name' => ['ar' => 'مغادرة موظف', 'en' => 'Leaver'], 'is_active' => true, 'items' => [
            $item('استرجاع بطاقة الدوام والمفاتيح', 'Return the staff card and keys', 0),
            $item('مقابلة المغادرة', 'Exit interview', -1),
            $item('التسوية المالية النهائية', 'Final settlement', 5),
        ]]);

        // Loans need their manager, then whoever approves pay; the rest go
        // to the direct manager alone (no flow).
        ApprovalFlow::query()->create([
            'request_type' => LoanService::APPROVAL_TYPE,
            'name' => ['ar' => 'السلف: المدير ثم اعتماد الرواتب', 'en' => 'Loans: manager, then payroll approval'],
            'steps' => [['approver' => 'direct_manager'], ['approver' => 'ability', 'ability' => 'hr.payroll.approve']],
            'is_active' => true,
        ]);
        ApprovalFlow::query()->create([
            'request_type' => LearningRequestService::APPROVAL_TYPE,
            'name' => ['ar' => 'بدل التعلّم: رئيس الوحدة', 'en' => 'Learning: head of unit'],
            'steps' => [['approver' => 'unit_head', 'timeout_hours' => 72, 'on_timeout' => 'escalate']],
            'is_active' => true,
        ]);

        // A custom role holding every HR permission: HR work without being an admin.
        $guard = Role::findByName('admin')->guard_name;
        Role::findOrCreate('hr_manager', $guard)->syncPermissions(Permission::query()->where('name', 'like', 'hr.%')->where('guard_name', $guard)->get());
    }

    /**
     * One person per employment situation. Dates count from today.
     */
    private function seedStaff(): void
    {
        $t = $this->today;

        $this->hireDemo('ceo', 'سامر الحلبي', 'male', $t->subMonths(40), 'GM', 'GM-01', null, ['contract' => 'open_ended']);
        $this->hireDemo('ops_manager', 'ريم الشامي', 'female', $t->subMonths(38), 'OPS', 'OPS-MGR', 'ceo', ['roles' => ['operations'], 'member' => true]);
        $this->hireDemo('hr_officer', 'دانة الخطيب', 'female', $t->subMonths(22), 'FIN', 'HR-OFFICER', 'ceo', ['roles' => ['hr_manager']]);
        $this->hireDemo('accountant', 'رنا السيد', 'female', $t->subMonths(20), 'FIN', 'ACCOUNTANT', 'ceo', ['roles' => ['hr_manager']]);
        $this->hireDemo('reception_lead', 'مازن قطان', 'male', $t->subMonths(24), 'RCP', 'RCP-LEAD', 'ops_manager', ['roles' => ['operations'], 'pattern' => 'roster']);
        $this->hireDemo('sales_manager', 'هبة الأتاسي', 'female', $t->subMonths(26), 'SALES', 'SALES-REP', 'ceo');
        $this->hireDemo('developer', 'باسل نحاس', 'male', $t->subMonths(14), 'LAB', 'DEV', 'ceo', ['contract' => 'hourly', 'pattern' => 'flexible']);

        // Probation: a member who joined the team keeps their account; one whose probation ends within the alert window.
        $this->hireDemo('receptionist_new', 'لينا عبيد', 'female', $t->subMonth(), 'RCP', 'RCP-AGT', 'reception_lead', ['probation' => $t->addMonths(2), 'pattern' => 'roster', 'member' => true]);
        $this->hireDemo('receptionist_probation', 'يزن دبس', 'male', $t->subDays(80), 'RCP', 'RCP-AGT', 'reception_lead', ['probation' => $t->addDays(10), 'pattern' => 'roster']);

        // A fixed-term contract ending within the alert window, part time.
        $this->hireDemo('barista_fixed', 'نور حداد', 'female', $t->subMonths(11), 'CAFE', 'BARISTA', 'ops_manager', ['contract' => 'fixed_term', 'ends' => $t->addDays(20), 'type' => 'part_time']);
        $this->hireDemo('barista_suspended', 'خالد عيسى', 'male', $t->subMonths(14), 'CAFE', 'BARISTA', 'ops_manager');
        $this->hireDemo('sales_rep', 'عمر الجابري', 'male', $t->subMonths(18), 'OPS', 'OPS-AGT', 'ops_manager');
        $this->hireDemo('ops_agent', 'وسيم زين', 'male', $t->subMonths(7), 'OPS', 'OPS-AGT', 'ops_manager', ['contract' => 'fixed_term', 'ends' => $t->subMonths(1), 'probation' => $t->subMonths(4)]);
        $this->hireDemo('intern', 'سلمى كيالي', 'female', $t->subMonths(2), 'LAB', 'INTERN', 'developer', ['contract' => 'internship', 'ends' => $t->addMonths(2), 'type' => 'part_time', 'mode' => 'hybrid', 'pattern' => 'flexible']);
        $this->hireDemo('resigned', 'طارق ملا', 'male', $t->subMonths(16), 'CAFE', 'BARISTA', 'ops_manager');
        $this->hireDemo('leaving', 'جود صباغ', 'female', $t->subMonths(15), 'RCP', 'RCP-AGT', 'reception_lead', ['pattern' => 'roster']);
        $this->hireDemo('rehired', 'فادي مارديني', 'male', $t->subMonths(36), 'OPS', 'OPS-AGT', 'ops_manager');
        $this->hireDemo('upcoming', 'أحمد ترك', 'male', $t->addDays(12), 'CAFE', 'BARISTA', 'ops_manager', ['probation' => $t->addDays(12)->addMonths(3)]);

        foreach (['GM' => 'ceo', 'OPS' => 'ops_manager', 'RCP' => 'reception_lead', 'SALES' => 'sales_manager', 'FIN' => 'hr_officer', 'LAB' => 'developer'] as $unit => $head) {
            $this->units[$unit]->update(['head_employee_id' => $this->employee($head)->id]);
        }
    }

    /**
     * Every kind of career event, on the dates that make each status true today.
     */
    private function seedCareers(): void
    {
        $t = $this->today;
        $lifecycle = app(EmploymentLifecycleService::class);
        $record = fn (string $key, EmploymentEventType $type, array $data) => $lifecycle->record($this->employee($key)->refresh(), $type, $data, $this->hrActor());

        // Promotion: a sales rep became the sales manager.
        $record('sales_manager', EmploymentEventType::Promotion, ['effective_on' => $t->subMonths(4)->toDateString(), 'notes' => 'ترقية بعد نتائج الربع الثاني',
            'assignment' => ['position_id' => $this->positions['SALES-MGR']->id, 'job_grade_id' => $this->grades['G4']->id]]);

        // Transfer: operations to sales, with a new manager.
        $record('sales_rep', EmploymentEventType::Transfer, ['effective_on' => $t->subMonths(2)->toDateString(), 'notes' => 'نقل إلى فريق المبيعات',
            'assignment' => ['org_unit_id' => $this->units['SALES']->id, 'position_id' => $this->positions['SALES-REP']->id, 'job_grade_id' => $this->grades['G3']->id, 'manager_id' => $this->employee('sales_manager')->id]]);

        // Assignment change: on site to remote.
        $record('developer', EmploymentEventType::AssignmentChange, ['effective_on' => $t->subMonths(3)->toDateString(), 'assignment' => ['work_mode' => 'remote']]);

        // Confirmation after probation, then the fixed-term contract renewed open-ended.
        $record('ops_agent', EmploymentEventType::Confirmation, ['effective_on' => $t->subMonths(4)->addDay()->toDateString(), 'notes' => 'اجتاز فترة التجربة']);
        $record('ops_agent', EmploymentEventType::ContractRenewal, ['effective_on' => $t->subMonths(1)->addDay()->toDateString(), 'contract' => ['type' => 'open_ended', 'notice_period_days' => 30]]);

        // A suspension that ended (reinstated), and one still running.
        $record('reception_lead', EmploymentEventType::Suspension, ['effective_on' => $t->subDays(45)->toDateString(), 'reason' => 'investigation', 'notes' => 'تحقيق إداري']);
        $record('reception_lead', EmploymentEventType::Reinstatement, ['effective_on' => $t->subDays(40)->toDateString(), 'notes' => 'أُغلق التحقيق']);
        $record('barista_suspended', EmploymentEventType::Suspension, ['effective_on' => $t->subDays(5)->toDateString(), 'reason' => 'disciplinary', 'notes' => 'إيقاف مؤقت حتى انتهاء التحقيق']);

        // Left last month (access already revoked); leaving in three weeks.
        $record('resigned', EmploymentEventType::Termination, ['effective_on' => $t->subDays(25)->toDateString(), 'reason' => 'resignation', 'notes' => 'استقالة لظروف عائلية']);
        $record('leaving', EmploymentEventType::Termination, ['effective_on' => $t->addDays(20)->toDateString(), 'reason' => 'resignation', 'notes' => 'قدّمت استقالتها مع مهلة الإشعار']);

        // Left over a year ago, rehired three months ago into the café.
        $record('rehired', EmploymentEventType::Termination, ['effective_on' => $t->subMonths(14)->toDateString(), 'reason' => 'end_of_contract']);
        $record('rehired', EmploymentEventType::Rehire, ['effective_on' => $t->subMonths(3)->toDateString(), 'notes' => 'عودة إلى الفريق',
            'contract' => ['type' => 'open_ended', 'probation_ends_on' => $t->subMonths(3)->addDays(45)->toDateString()],
            'assignment' => ['org_unit_id' => $this->units['CAFE']->id, 'position_id' => $this->positions['BARISTA']->id, 'job_grade_id' => $this->grades['G1']->id,
                'manager_id' => $this->employee('ops_manager')->id, 'work_location_id' => $this->branchId, 'employment_type' => 'full_time', 'work_mode' => 'on_site',
                'work_pattern_id' => $this->patterns['office']->id]]);
        $record('rehired', EmploymentEventType::Confirmation, ['effective_on' => $t->subMonths(3)->addDays(45)->toDateString()]);

        // An approver away next week hands their approvals to their lead.
        ApprovalDelegation::query()->create([
            'delegator_employee_id' => $this->employee('ops_manager')->id,
            'delegate_employee_id' => $this->employee('reception_lead')->id,
            'starts_on' => $this->workday($t->addWeek())->toDateString(),
            'ends_on' => $this->workday($t->addWeek())->addDays(4)->toDateString(),
            'created_by' => $this->hrActor(),
        ]);
    }

    /**
     * Emergency contacts, dependents, documents (on file, expiring, expired,
     * missing) and checklists part done.
     */
    private function seedPersonalFiles(): void
    {
        $t = $this->today;

        foreach (['ceo' => ['هالة الحلبي', 'زوجة'], 'ops_manager' => ['محمد الشامي', 'أخ'], 'receptionist_new' => ['سعاد عبيد', 'أم'], 'developer' => ['ميساء نحاس', 'أخت']] as $key => [$name, $relation]) {
            EmployeeContact::query()->create(['employee_id' => $this->employee($key)->id, 'name' => $name, 'relation' => $relation, 'phone' => '+9639445'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT)]);
        }
        EmployeeDependent::query()->create(['employee_id' => $this->employee('ceo')->id, 'name' => 'ليث الحلبي', 'relation' => 'ابن', 'date_of_birth' => $t->subYears(9)->toDateString()]);
        EmployeeDependent::query()->create(['employee_id' => $this->employee('ceo')->id, 'name' => 'تالا الحلبي', 'relation' => 'ابنة', 'date_of_birth' => $t->subYears(6)->toDateString()]);
        EmployeeDependent::query()->create(['employee_id' => $this->employee('ops_manager')->id, 'name' => 'كريم الشامي', 'relation' => 'ابن', 'date_of_birth' => $t->subYears(3)->toDateString()]);

        $vault = app(DocumentVault::class);
        $file = fn (string $name) => UploadedFile::fake()->create($name, 24, 'application/pdf');
        $store = fn (string $key, string $type, ?CarbonImmutable $expires, ?CarbonImmutable $issued = null) => $vault->store(
            $this->employee($key), $this->documentTypes[$type], $file("{$type}.pdf"),
            ['issued_on' => ($issued ?? $t->subYears(2))->toDateString(), 'expires_on' => $expires?->toDateString()],
            $this->hrActor(),
        );

        foreach (['ceo', 'ops_manager', 'hr_officer', 'accountant', 'sales_manager', 'developer'] as $key) {
            $store($key, 'national-id', $t->addYears(5));
            $store($key, 'contract', null);
        }
        $store('reception_lead', 'national-id', $t->addDays(30));                   // expiring soon
        $store('barista_fixed', 'health', $t->subDays(10), $t->subYear());          // expired
        $store('barista_suspended', 'health', $t->addMonths(4), $t->subMonths(8));
        $store('intern', 'contract', null);                                         // internship letter missing

        // Checklists made at hire: the older staff finished theirs, the newest are under way.
        ChecklistTask::query()->where('kind', 'onboarding')
            ->whereNotIn('employee_id', [$this->employee('receptionist_new')->id, $this->employee('upcoming')->id, $this->employee('intern')->id])
            ->update(['completed_at' => now(), 'completed_by' => $this->hrActor()]);
        ChecklistTask::query()->where('employee_id', $this->employee('receptionist_new')->id)->orderBy('sort')->limit(2)
            ->update(['completed_at' => now(), 'completed_by' => $this->hrActor()]);
        ChecklistTask::query()->where('employee_id', $this->employee('resigned')->id)->where('kind', 'offboarding')
            ->update(['completed_at' => now(), 'completed_by' => $this->hrActor()]);
    }

    /**
     * An account (password `password`) and its hire.
     *
     * @param  array{contract?: string, ends?: CarbonImmutable, probation?: CarbonImmutable, type?: string, mode?: string, pattern?: string, roles?: list<string>, member?: bool}  $o
     */
    private function hireDemo(string $key, string $name, string $gender, CarbonImmutable $hiredOn, string $unit, string $position, ?string $manager, array $o = []): void
    {
        $n = count($this->staff) + 1;
        $email = str_replace('_', '.', $key).'@staff.demo';

        $user = app(AccountCreationService::class)->create([
            'name' => $name,
            'phone' => '+96394450'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => $email,
        ], null, 'password');

        foreach ($o['roles'] ?? [] as $role) {
            $user->assignRole($role);
        }

        if ($o['member'] ?? false) {
            $user->assignRole('member');
            Wallet::query()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
        }

        $positionModel = $this->positions[$position];
        $contract = $o['contract'] ?? 'open_ended';

        $this->staff[$key] = app(EmploymentLifecycleService::class)->hire([
            'user_id' => $user->id,
            'gender' => $gender,
            'date_of_birth' => $this->today->subYears(24 + ($n * 3) % 20)->subDays($n * 17)->toDateString(),
            'nationality' => $n % 7 === 0 ? 'LB' : 'SY',
            'marital_status' => ['single', 'married', 'single', 'married', 'divorced'][$n % 5],
            'personal_email' => $n % 3 === 0 ? null : "personal{$n}@mail.demo",
            'address' => ['حلب - الفرقان', 'حلب - الجميلية', 'حلب - الموكامبو', 'حلب - السبيل', null][$n % 5],
            'national_id' => $n % 4 === 0 ? null : '0201'.str_pad((string) ($n * 7919), 7, '0', STR_PAD_LEFT),
            'bank_account' => $n % 3 === 1 ? null : 'SY21-0012-'.str_pad((string) ($n * 104729), 10, '0', STR_PAD_LEFT),
            'hired_on' => $hiredOn->toDateString(),
            'contract' => [
                'type' => $contract,
                'ends_on' => isset($o['ends']) ? $o['ends']->toDateString() : null,
                'probation_ends_on' => isset($o['probation']) ? $o['probation']->toDateString() : null,
                'notice_period_days' => $contract === 'open_ended' ? 30 : 15,
                'reference' => 'CT-'.$hiredOn->format('Y').'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
            ],
            'assignment' => [
                'org_unit_id' => $this->units[$unit]->id,
                'position_id' => $positionModel->id,
                'job_grade_id' => $positionModel->job_grade_id,
                'manager_id' => $manager ? $this->employee($manager)->id : null,
                'work_location_id' => $this->branchId,
                'employment_type' => $o['type'] ?? 'full_time',
                'work_mode' => $o['mode'] ?? 'on_site',
                'work_pattern_id' => $this->patterns[$o['pattern'] ?? 'office']->id,
            ],
        ], $this->staff['hr_officer']->user_id ?? $this->admin->id);
    }
}
