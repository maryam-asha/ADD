<?php

namespace Add\Hr\Payroll\Services;

use Add\Hr\Approvals\Services\ApprovalEngine;
use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Enums\InstallmentStatus;
use Add\Hr\Payroll\Enums\LoanStatus;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\Loan;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Support\Decimal;
use Add\Hr\Support\HrException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Salary advances. A request goes through the approval engine (`loan`);
 * approval lays out the installments, which payroll deducts month by
 * month. Limits (hr.payroll.loans): at most max_installments, at most
 * max_salary_multiple × the monthly pay on base_components, one open loan
 * at a time.
 */
class LoanService
{
    public const APPROVAL_TYPE = 'loan';

    public function __construct(
        private readonly ApprovalEngine $approvals,
        private readonly ExchangeRateProvider $rates,
    ) {}

    /**
     * @param  array{amount: string|float|int, currency: string, installments: int, first_month?: ?string, reason?: ?string}  $data
     */
    public function request(Employee $employee, array $data, int|string|null $actorId): Loan
    {
        return DB::transaction(function () use ($employee, $data, $actorId) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $config = (array) config('hr.payroll.loans', []);
            $currency = strtoupper($data['currency']);
            $installments = (int) $data['installments'];

            if ($installments > (int) ($config['max_installments'] ?? 24)) {
                HrException::fail('hr::payroll.loan_too_many_installments', ['max' => (int) ($config['max_installments'] ?? 24)]);
            }

            if (Loan::query()->where('employee_id', $employee->id)->whereIn('status', [LoanStatus::Pending->value, LoanStatus::Active->value])->exists()) {
                HrException::fail('hr::payroll.loan_open');
            }

            $cap = Decimal::mul($this->monthlyPay($employee, (array) ($config['base_components'] ?? ['basic']), $currency), (string) ($config['max_salary_multiple'] ?? 2));

            if (Decimal::cmp($data['amount'], $cap) > 0) {
                HrException::fail('hr::payroll.loan_over_cap', ['max' => Decimal::round($cap), 'currency' => $currency]);
            }

            $first = isset($data['first_month'])
                ? CarbonImmutable::createFromFormat('Y-m', $data['first_month'])->startOfMonth()
                : CarbonImmutable::today()->addMonthNoOverflow()->startOfMonth();

            $loan = Loan::query()->create([
                'employee_id' => $employee->id,
                'amount' => $data['amount'],
                'currency' => $currency,
                'installments' => $installments,
                'installment_amount' => Decimal::round(Decimal::div($data['amount'], (string) $installments)),
                'first_year' => $first->year,
                'first_month' => $first->month,
                'reason' => $data['reason'] ?? null,
                'status' => LoanStatus::Pending,
                'submitted_by' => $actorId,
            ]);

            $loan->approval_request_id = $this->approvals->submit($loan, self::APPROVAL_TYPE, $employee, $actorId)->id;
            $loan->save();

            HrAudit::record('loan_requested', $loan, ['amount' => $loan->amount, 'currency' => $currency]);

            return $loan;
        });
    }

    /**
     * Lays out the installments; the last takes what rounding left.
     */
    public function activate(Loan $loan): void
    {
        $month = CarbonImmutable::create($loan->first_year, $loan->first_month, 1);
        $left = $loan->amount;

        for ($i = 1; $i <= $loan->installments; $i++, $month = $month->addMonthNoOverflow()) {
            $amount = $i === $loan->installments ? $left : $loan->installment_amount;
            $left = Decimal::sub($left, $amount);
            $loan->schedule()->create(['year' => $month->year, 'month' => $month->month, 'amount' => $amount, 'status' => InstallmentStatus::Due]);
        }

        $loan->update(['status' => LoanStatus::Active]);
    }

    public function cancel(Loan $loan): void
    {
        DB::transaction(function () use ($loan) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            if ($loan->status !== LoanStatus::Pending) {
                HrException::fail('hr::time.not_pending');
            }

            $this->approvals->cancel($loan->approval);
            $loan->update(['status' => LoanStatus::Cancelled]);
        });
    }

    /**
     * What is still due is settled outside payroll (paid back in cash, or in
     * a final settlement's adjustment).
     */
    public function settle(Loan $loan, string $note, int|string|null $actorId): void
    {
        DB::transaction(function () use ($loan, $note, $actorId) {
            $loan = Loan::query()->lockForUpdate()->findOrFail($loan->id);

            if ($loan->status !== LoanStatus::Active) {
                HrException::fail('hr::payroll.loan_not_active');
            }

            $loan->schedule()->where('status', InstallmentStatus::Due->value)->update(['status' => InstallmentStatus::Settled->value, 'note' => mb_substr($note, 0, 255)]);
            $loan->update(['status' => LoanStatus::Settled]);
            HrAudit::record('loan_settled', $loan, ['note' => $note, 'by' => $actorId]);
        });
    }

    public function outstanding(Loan $loan): string
    {
        return Decimal::add('0', ...$loan->schedule()->where('status', InstallmentStatus::Due->value)->pluck('amount')->all());
    }

    /**
     * @param  list<string>  $codes
     */
    private function monthlyPay(Employee $employee, array $codes, string $currency): string
    {
        $today = CarbonImmutable::today();
        $ids = PayComponent::query()->whereIn('code', $codes)->pluck('id');
        $total = '0';

        foreach (Compensation::query()->where('employee_id', $employee->id)->whereIn('pay_component_id', $ids)->overlapping($today, $today)->get() as $line) {
            $rate = $this->rates->rate($line->currency, $currency) ?? HrException::fail('hr::payroll.missing_rate', ['from' => $line->currency, 'to' => $currency]);
            $total = Decimal::add($total, Decimal::mul($line->amount, $rate));
        }

        return $total;
    }
}
