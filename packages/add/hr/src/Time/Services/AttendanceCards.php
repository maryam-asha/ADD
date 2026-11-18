<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Time\Models\AttendanceCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Employees' QR cards. A card's value is `{prefix}:{token}`; only the
 * token's hash is stored, so the value is shown once, when the card is
 * issued. Issuing again replaces the card (a lost one stops working).
 */
class AttendanceCards
{
    /**
     * @return string the value to print in the card's QR
     */
    public function issue(Employee $employee, int|string|null $actorId): string
    {
        return DB::transaction(function () use ($employee, $actorId) {
            $this->revokeActive($employee);

            $token = Str::random(40);
            AttendanceCard::query()->create([
                'employee_id' => $employee->id,
                'token_hash' => hash('sha256', $token),
                'issued_by' => $actorId,
            ]);

            HrAudit::record('attendance_card_issued', $employee);

            return self::prefix().':'.$token;
        });
    }

    public function revoke(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $this->revokeActive($employee);
            HrAudit::record('attendance_card_revoked', $employee);
        });
    }

    public function active(Employee $employee): ?AttendanceCard
    {
        return AttendanceCard::query()->where('employee_id', $employee->id)->whereNull('revoked_at')->latest('id')->first();
    }

    /**
     * The employee a scanned value belongs to, or null for anything that is
     * not a live card.
     */
    public function resolve(string $value): ?Employee
    {
        $prefix = self::prefix().':';
        $value = trim($value);

        if (! str_starts_with($value, $prefix)) {
            return null;
        }

        $card = AttendanceCard::query()
            ->where('token_hash', hash('sha256', substr($value, strlen($prefix))))
            ->whereNull('revoked_at')
            ->first();

        return $card?->employee;
    }

    public static function prefix(): string
    {
        return (string) config('hr.attendance.card_prefix', 'hr-card:v1');
    }

    private function revokeActive(Employee $employee): void
    {
        AttendanceCard::query()->where('employee_id', $employee->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
