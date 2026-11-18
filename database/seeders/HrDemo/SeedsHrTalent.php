<?php

namespace Database\Seeders\HrDemo;

use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Performance\Models\Review;
use Add\Hr\Performance\Models\ReviewCycle;
use Add\Hr\Performance\Models\ReviewTemplate;
use Add\Hr\Performance\Services\GoalService;
use Add\Hr\Performance\Services\ReviewCycleService;
use Add\Hr\Performance\Services\ReviewService;
use Add\Hr\Recruitment\Enums\CandidateSource;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Services\ApplicationService;
use Add\Hr\Recruitment\Services\InterviewService;
use Add\Hr\Recruitment\Services\JobOpeningService;
use Add\Hr\Recruitment\Services\OfferService;
use Add\Hr\Recruitment\Services\RecruitmentService;
use Add\Hr\Recruitment\Services\RequisitionService;
use Add\Hr\Training\Models\Course;
use Add\Hr\Training\Models\Enrollment;
use Add\Hr\Training\Models\TrainingSession;
use Add\Hr\Training\Services\LearningBudget;
use Add\Hr\Training\Services\LearningRequestService;
use Add\Hr\Training\Services\TrainingService;

/**
 * Recruitment (requisitions approved, waiting and refused; a public and an
 * internal opening; a candidate at every stage, interviews scored and
 * coming, an offer waiting and one accepted and hired), performance (a
 * cycle in self review with goals and submitted reviews, next year's in
 * draft) and training (completed sessions whose certificates are about to
 * expire, an upcoming one with seats taken, a cancelled one, the learning
 * budget and its requests).
 */
trait SeedsHrTalent
{
    private function seedRecruitment(): void
    {
        $t = $this->today;
        $requisitions = app(RequisitionService::class);
        $openings = app(JobOpeningService::class);
        $applications = app(ApplicationService::class);
        $interviews = app(InterviewService::class);
        $offers = app(OfferService::class);
        $actor = $this->hrActor();

        $file = fn (string $manager, string $unit, string $position, string $ar, string $en, int $headcount, string $reason, ?string $justification = null) => $requisitions->file([
            'org_unit_id' => $this->units[$unit]->id,
            'position_id' => $this->positions[$position]->id,
            'job_grade_id' => $this->positions[$position]->job_grade_id,
            'title' => ['ar' => $ar, 'en' => $en],
            'headcount' => $headcount,
            'employment_type' => 'full_time',
            'reason' => $reason,
            'budget_min' => 300,
            'budget_max' => 450,
            'budget_currency' => 'USD',
            'justification' => $justification,
        ], $this->employee($manager), (int) $this->employee($manager)->user_id);

        // Approved → a public opening with a candidate at every stage.
        $baristas = $file('ops_manager', 'CAFE', 'BARISTA', 'باريستا', 'Barista', 2, 'new_position', 'افتتاح ركن القهوة الثاني في الطابق الأول');
        $this->approve($baristas->approval_request_id, 'ceo');
        $opening = $openings->create($baristas->refresh(), [
            'visibility' => 'public',
            'description' => ['ar' => 'نبحث عن باريستا شغوف بالقهوة المختصة للانضمام إلى مقهى الحي.', 'en' => 'We are looking for a barista who loves specialty coffee.'],
            'work_location_id' => $this->branchId,
            'closes_on' => $t->addMonth()->toDateString(),
            'open' => true,
        ], $actor);

        $apply = fn (string $name, string $email, string $phone, CandidateSource $source = CandidateSource::Careers, array $extra = []) => $applications->apply(
            $opening, ['name' => $name, 'email' => $email, 'phone' => $phone], null, $source,
            ['consent' => true, 'cover_letter' => 'أحب العمل مع الناس ولدي خبرة في تحضير القهوة.', ...$extra], $source === CandidateSource::Careers ? null : $actor,
        );

        $apply('سارة يوسف', 'sara.yousef@candidates.demo', '+963944610001');                                   // applied
        $apply('يامن قباني', 'yamen.q@candidates.demo', '+963944610008', CandidateSource::Referral, ['referred_by_employee_id' => $this->employee('barista_fixed')->id]);
        $screening = $apply('كنان حمامي', 'kinan.h@candidates.demo', '+963944610002');
        $applications->move($screening, 'screening', 'سيرة مناسبة', $actor);

        $upcoming = $apply('ميار طباع', 'mayar.t@candidates.demo', '+963944610003');
        $applications->move($upcoming, 'screening', null, $actor);
        $applications->move($upcoming->refresh(), 'interview', null, $actor);
        $interviews->schedule($upcoming->refresh(), ['scheduled_at' => $this->workday($t->addDays(3))->setTime(11, 0)->format('Y-m-d H:i'), 'duration_minutes' => 45,
            'mode' => 'in_person', 'location' => 'المقهى - الطابق الأرضي', 'interviewer_ids' => [$this->employee('ops_manager')->id, $this->employee('barista_fixed')->id]], $actor);

        $interviewed = function (string $name, string $email, string $phone) use ($apply, $applications, $interviews, $actor, $t): Application {
            $application = $apply($name, $email, $phone);
            $applications->move($application, 'screening', null, $actor);
            $applications->move($application->refresh(), 'interview', null, $actor);
            $interview = $interviews->schedule($application->refresh(), ['scheduled_at' => $this->workday($t->subDays(6))->setTime(10, 0)->format('Y-m-d H:i'), 'duration_minutes' => 30,
                'mode' => 'video', 'interviewer_ids' => [$this->employee('ops_manager')->id, $this->employee('reception_lead')->id]], $actor);
            $interviews->score($interview, $this->employee('ops_manager'), ['recommendation' => 'yes', 'ratings' => ['communication' => 4, 'coffee_skills' => 5], 'notes' => 'مهارة ممتازة في الإسبريسو']);
            $interviews->score($interview, $this->employee('reception_lead'), ['recommendation' => 'strong_yes', 'ratings' => ['communication' => 5], 'notes' => 'تواصل رائع مع الضيوف']);

            return $application->refresh();
        };
        $offer = ['start_on' => $this->workday($t->addDays(14))->toDateString(), 'expires_on' => $t->addDays(5)->toDateString(),
            'compensation' => [['pay_component_id' => PayComponent::query()->where('code', 'basic')->value('id'), 'amount' => 340, 'currency' => 'USD']],
            'terms' => 'دوام كامل، فترة تجربة ثلاثة أشهر.'];

        // An offer waiting for the hiring manager's manager.
        $offers->make($interviewed('ربيع عطار', 'rabie.a@candidates.demo', '+963944610004'), $offer, $actor);

        // An offer approved and accepted, then hired: starts in two weeks.
        $hired = $interviewed('آية دلال', 'aya.d@candidates.demo', '+963944610005');
        $accepted = $offers->make($hired, $offer, $actor);
        $this->approve($accepted->approval_request_id, 'ceo');
        $offers->accept($accepted->refresh());
        $this->staff['hired_candidate'] = app(RecruitmentService::class)->hire($hired->refresh(), [
            'name' => ['ar' => 'آية دلال', 'en' => 'Aya Dallal'],
            'phone' => '+963944610005',
            'work_email' => 'aya.dallal@staff.demo',
            'personal_email' => 'aya.d@candidates.demo',
            'hired_on' => $offer['start_on'],
            'contract' => ['type' => 'open_ended', 'probation_ends_on' => $this->today->addDays(14)->addMonths(3)->toDateString()],
            'assignment' => ['org_unit_id' => $this->units['CAFE']->id, 'position_id' => $this->positions['BARISTA']->id, 'job_grade_id' => $this->grades['G1']->id,
                'manager_id' => $this->employee('ops_manager')->id, 'work_location_id' => $this->branchId, 'employment_type' => 'full_time', 'work_mode' => 'on_site',
                'work_pattern_id' => $this->patterns['office']->id],
        ], $actor);

        $rejected = $apply('جورج نصار', 'george.n@candidates.demo', '+963944610006');
        $applications->move($rejected, 'screening', null, $actor);
        $applications->reject($rejected->refresh(), 'لا تتوفر خبرة كافية في التعامل مع الزبائن', $actor);
        $applications->withdraw($apply('ليلى حجار', 'layla.h@candidates.demo', '+963944610007'), 'قبلت عرضاً آخر', $actor);

        // Waiting for approval; refused; approved for an internal opening.
        $file('sales_manager', 'SALES', 'SALES-REP', 'مندوب مبيعات', 'Sales representative', 1, 'replacement', 'تعويض انتقال أحد المندوبين');
        $refused = $file('developer', 'LAB', 'DEV', 'مطوّر واجهات', 'Front-end developer', 1, 'new_position');
        $this->approve($refused->approval_request_id, 'ceo', reject: true, comment: 'الميزانية لا تسمح هذا الربع');
        $interns = $file('developer', 'LAB', 'INTERN', 'متدرّب تطوير', 'Development intern', 1, 'new_position');
        $this->approve($interns->approval_request_id, 'ceo');
        $openings->create($interns->refresh(), ['visibility' => 'internal', 'description' => ['ar' => 'فرصة تدريب داخلية لموظفي الحي المهتمين بالبرمجة.'], 'open' => true], $actor);
    }

    private function seedPerformance(): void
    {
        $t = $this->today;
        $template = ReviewTemplate::query()->create([
            'name' => ['ar' => 'التقييم السنوي', 'en' => 'Annual review'],
            'scale_max' => 5,
            'goals_weight' => 40,
            'competencies' => [
                ['code' => 'teamwork', 'name' => ['ar' => 'العمل الجماعي', 'en' => 'Teamwork'], 'weight' => 2],
                ['code' => 'service', 'name' => ['ar' => 'خدمة الأعضاء', 'en' => 'Member service'], 'weight' => 2],
                ['code' => 'ownership', 'name' => ['ar' => 'تحمّل المسؤولية', 'en' => 'Ownership'], 'weight' => 1],
            ],
            'is_active' => true,
        ]);

        $cycle = ReviewCycle::query()->create([
            'name' => ['ar' => "تقييم {$t->year}", 'en' => "{$t->year} review"],
            'review_template_id' => $template->id,
            'period_start' => $t->startOfYear()->toDateString(),
            'period_end' => $t->endOfYear()->toDateString(),
            'goals_due_on' => $t->subDays(10)->toDateString(),
            'self_due_on' => $t->addDays(10)->toDateString(),
            'manager_due_on' => $t->addDays(25)->toDateString(),
            'status' => 'draft',
            'created_by' => $this->hrActor(),
        ]);
        $cycles = app(ReviewCycleService::class);
        $cycles->launch($cycle);

        $goals = app(GoalService::class);
        $goal = fn (string $key, string $title, int $weight, string $by, array $extra = []) => $goals->create($this->employee($key), [
            'review_cycle_id' => $cycle->id, 'title' => $title, 'weight' => $weight, ...$extra,
        ], (int) $this->employee($by)->user_id);

        $goal('sales_rep', 'توقيع 12 عقد عضوية شركات', 60, 'sales_manager', ['metric' => 'عقود', 'target' => '12', 'due_on' => $t->endOfYear()->toDateString()]);
        $goal('sales_rep', 'تجديد 90% من العقود المنتهية', 40, 'sales_manager');
        $goal('developer', 'إطلاق وحدة الموارد البشرية في اللوحة', 70, 'ceo');
        $goal('developer', 'تغطية اختبارات 80%', 30, 'ceo');
        $goal('receptionist_new', 'تقييم رضا الضيوف 4.5 فأكثر', 100, 'reception_lead');

        // Self review open: two employees have submitted theirs.
        $cycles->advance($cycle->refresh());
        $answers = [['kind' => 'competency', 'key' => 'teamwork', 'rating' => 4], ['kind' => 'competency', 'key' => 'service', 'rating' => 5], ['kind' => 'competency', 'key' => 'ownership', 'rating' => 4]];
        foreach (['sales_rep' => 'عام قوي في المبيعات رغم انتقالي من العمليات.', 'developer' => 'أنجزت معظم أهداف الوحدة.'] as $key => $comment) {
            $review = Review::query()->where('review_cycle_id', $cycle->id)->where('employee_id', $this->employee($key)->id)->first();
            if ($review !== null) {
                app(ReviewService::class)->submitSelf($review, $this->employee($key), ['answers' => $answers, 'comment' => $comment]);
            }
        }

        ReviewCycle::query()->create([
            'name' => ['ar' => 'تقييم '.($t->year + 1), 'en' => ($t->year + 1).' review'],
            'review_template_id' => $template->id,
            'period_start' => $t->addYear()->startOfYear()->toDateString(),
            'period_end' => $t->addYear()->endOfYear()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->hrActor(),
        ]);
    }

    private function seedTraining(): void
    {
        $t = $this->today;
        $training = app(TrainingService::class);
        $actor = $this->hrActor();

        $course = fn (string $code, string $ar, string $en, string $delivery, ?int $certMonths, ?string $certAr, int $hours, ?int $cost) => Course::query()->create([
            'code' => $code, 'title' => ['ar' => $ar, 'en' => $en], 'description' => ['ar' => "دورة {$ar} لفريق الحي."], 'provider' => 'أكاديمية ADD',
            'delivery' => $delivery, 'duration_hours' => $hours, 'cost' => $cost, 'currency' => $cost === null ? null : 'USD',
            'certification_name' => $certAr ? ['ar' => $certAr] : null, 'certification_valid_months' => $certMonths, 'is_active' => true,
        ]);
        $firstAid = $course('first-aid', 'الإسعافات الأولية', 'First aid', 'in_person', 12, 'شهادة إسعافات أولية', 8, 40);
        $barista = $course('barista-101', 'أساسيات الباريستا', 'Barista basics', 'in_person', 24, 'شهادة باريستا', 12, 60);
        $laravel = $course('laravel-advanced', 'Laravel المتقدم', 'Advanced Laravel', 'online', null, null, 20, 120);
        $service = $course('guest-service', 'خدمة الضيوف', 'Guest service', 'blended', null, null, 6, null);

        $session = fn (Course $course, int $from, int $to, int $capacity, bool $self, ?string $location = null) => TrainingSession::query()->create([
            'course_id' => $course->id, 'starts_on' => $t->addDays($from)->toDateString(), 'ends_on' => $t->addDays($to)->toDateString(),
            'location' => $location ?? 'قاعة التدريب - الطابق الثاني', 'trainer' => 'م. سوزان حداد', 'capacity' => $capacity, 'self_enrollment' => $self,
            'status' => 'scheduled', 'created_by' => $actor,
        ]);

        // Done eleven months ago: its certificates expire within the alert window; one no-show.
        $aid = $session($firstAid, -340, -339, 10, false);
        foreach (['ceo', 'ops_manager', 'reception_lead', 'hr_officer'] as $key) {
            $training->enroll($aid, $this->employee($key), $actor, false);
        }
        $noShow = Enrollment::query()->where('training_session_id', $aid->id)->where('employee_id', $this->employee('hr_officer')->id)->value('id');
        $training->completeSession($aid->refresh(), [$noShow], [], $actor);

        $coffee = $session($barista, -40, -38, 6, false, 'المقهى');
        foreach (['barista_fixed', 'rehired', 'barista_suspended'] as $key) {
            $training->enroll($coffee, $this->employee($key), $actor, false);
        }
        $training->completeSession($coffee->refresh(), [], [], $actor);

        // Coming up, open to self-enrolment; and one cancelled.
        $online = $session($laravel, 20, 22, 5, true, 'عبر الإنترنت');
        $training->enroll($online, $this->employee('developer'), $actor, false);
        $training->enroll($online, $this->employee('intern'), $actor, false);
        $training->enroll($online, $this->employee('sales_rep'), (int) $this->employee('sales_rep')->user_id, true);
        $training->cancelSession($session($service, 45, 45, 15, true));

        // The learning budget and what was asked of it.
        $budget = app(LearningBudget::class);
        $budget->allocate($t->year, '300', null, $actor);
        $requests = app(LearningRequestService::class);
        $ask = fn (string $key, string $title, int $amount, ?string $provider = null) => $requests->submit($this->employee($key), [
            'title' => $title, 'provider' => $provider, 'amount' => $amount, 'starts_on' => $t->addDays(15)->toDateString(), 'justification' => 'تطوير مهارات العمل الحالي',
        ], (int) $this->employee($key)->user_id);

        $this->approve($ask('developer', 'شهادة AWS Cloud Practitioner', 150, 'Amazon')->approval_request_id, 'ceo');
        $ask('hr_officer', 'دورة قانون العمل السوري', 100, 'نقابة المحامين');
        $this->approve($ask('sales_rep', 'مهارات التفاوض المتقدمة', 120)->approval_request_id, 'sales_manager', reject: true, comment: 'نؤجلها إلى الربع القادم');
    }
}
