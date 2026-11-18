<?php

namespace App\Domain\Hr\Kiosk;

use Add\Hr\Support\HrException;
use Add\Hr\Time\Enums\PunchSource;
use Add\Hr\Time\Services\AttendanceCards;
use Add\Hr\Time\Services\PunchRecorder;
use App\Domain\Booking\Models\Kiosk;
use Carbon\CarbonImmutable;

/**
 * Staff card scans from a reception kiosk, the way a fingerprint clock
 * reports them: each scan is a raw punch with the time the kiosk saw the
 * card. The kiosk may queue scans while offline and send them later, so a
 * scan up to MAX_AGE_HOURS old is accepted at its own time; each carries
 * the kiosk's id for it, and a resend is recognised rather than booked
 * twice. One result per scan, so the kiosk can greet the person or say why
 * not.
 */
class KioskPunchIntake
{
    public const MAX_AGE_HOURS = 72;

    private const FUTURE_SKEW_SECONDS = 120;

    public function __construct(
        private readonly AttendanceCards $cards,
        private readonly PunchRecorder $recorder,
    ) {}

    /**
     * @param  list<array{id: string, card: string, scanned_at: string}>  $scans
     * @return list<array{id: string, status: string, employee: ?array<string, mixed>, message: ?string}>
     */
    public function record(Kiosk $kiosk, array $scans): array
    {
        $now = CarbonImmutable::now();

        return array_map(function (array $scan) use ($kiosk, $now) {
            $at = CarbonImmutable::parse($scan['scanned_at']);

            if ($at->gt($now->addSeconds(self::FUTURE_SKEW_SECONDS)) || $at->lt($now->subHours(self::MAX_AGE_HOURS))) {
                return $this->result($scan, 'rejected', null, __('api.hr.kiosk_scan_out_of_window'));
            }

            $employee = $this->cards->resolve($scan['card']);

            if ($employee === null) {
                return $this->result($scan, 'unknown_card', null, __('api.hr.kiosk_unknown_card'));
            }

            try {
                $punch = $this->recorder->record($employee, $at, PunchSource::CardScan, [
                    'device_type' => 'kiosk',
                    'device_id' => (string) $kiosk->id,
                    'idempotency_key' => "kiosk:{$kiosk->id}:{$scan['id']}",
                ]);
            } catch (HrException $e) {
                return $this->result($scan, 'rejected', $employee->only(['id', 'name']), $e->getMessage());
            }

            return $this->result($scan, $punch->duplicate ? 'duplicate' : 'recorded', $employee->only(['id', 'name']), null);
        }, $scans);
    }

    /**
     * @param  array{id: string}  $scan
     * @param  array<string, mixed>|null  $employee
     * @return array{id: string, status: string, employee: ?array<string, mixed>, message: ?string}
     */
    private function result(array $scan, string $status, ?array $employee, ?string $message): array
    {
        return ['id' => $scan['id'], 'status' => $status, 'employee' => $employee, 'message' => $message];
    }
}
