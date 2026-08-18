<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Member-facing booking creation — the piece Reception Operations
 * deliberately deferred. Validation order matches the phase brief exactly:
 * business hours, then granularity, then duration (none of these depend on
 * shared state, so none need a lock); then, inside one locked transaction
 * on the Space row, overlap + live occupancy, then buffer; then payment;
 * then the requires_approval branch. Task 4 adds payment routing and Task 5
 * adds the requires_approval branch to this same create() method.
 */
class BookingCreationService
{
    public function __construct(
        private readonly BusinessHoursService $businessHours,
        private readonly SettingService $settings,
        private readonly AmountCalculator $amounts,
        private readonly WalletService $wallets,
    ) {}

    public function create(
        Space $space,
        User $member,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?OwnerType $walletOwnerType = null,
        ?int $walletOwnerId = null,
    ): Booking {
        // Normalize to UTC: Eloquent's datetime cast stores the Carbon
        // value's current wall-clock as-is and reinterprets it as UTC on
        // read, so a non-UTC input (e.g. a Damascus-timezone request time)
        // would round-trip with a 3-hour drift — the same fix
        // SessionClosureService::finalizeClosure() applies to
        // checked_out_at for the identical reason.
        $startAt = $startAt->copy()->setTimezone('UTC');
        $endAt = $endAt->copy()->setTimezone('UTC');

        $this->assertWithinBusinessHours($space, $startAt, $endAt);

        $granularity = $space->slot_granularity_minutes ?? $this->settings->get('booking.slot_granularity_minutes', 30);
        $this->assertValidGranularity($startAt, $granularity);

        $minDuration = (int) $this->settings->get('booking.min_duration_minutes', 60);
        $this->assertValidDuration($startAt, $endAt, $minDuration, $granularity);

        return DB::transaction(function () use ($space, $member, $startAt, $endAt, $walletOwnerType, $walletOwnerId) {
            $locked = Space::query()->whereKey($space->id)->lockForUpdate()->firstOrFail();

            $this->assertSlotAvailable($locked, $startAt, $endAt);
            $this->assertOccupancyLeavesRoom($locked);
            $this->assertBufferRespected($locked, $startAt, $endAt);

            [$amount] = $this->amounts->forRange($locked, $startAt, $endAt);
            [$paymentState, $paymentSource] = $this->routePayment($locked, $member, $amount, $walletOwnerType, $walletOwnerId);

            return Booking::create([
                'space_id' => $locked->id,
                'user_id' => $member->id,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => BookingStatus::Confirmed,
                'payment_state' => $paymentState,
                'payment_source' => $paymentSource,
            ]);
        });
    }

    /**
     * @return array{0: PaymentState, 1: ?PaymentSource}
     */
    private function routePayment(Space $space, User $member, string $amount, ?OwnerType $walletOwnerType, ?int $walletOwnerId): array
    {
        if (bccomp($amount, '0.00', 2) <= 0) {
            return [PaymentState::Unpaid, null];
        }

        if ($walletOwnerType !== null && $walletOwnerId !== null) {
            $wallet = $this->wallets->walletFor($walletOwnerType, $walletOwnerId);
            $this->wallets->debit($wallet, $member, WalletTransactionCategory::SpaceSpecific, $amount, "Booking for space #{$space->id}");

            return [PaymentState::Paid, PaymentSource::Wallet];
        }

        $options = $this->wallets->spendOptions($member, WalletTransactionCategory::SpaceSpecific);

        if (count($options) > 1) {
            throw new WalletChoiceRequiredException($options);
        }

        if (count($options) === 1) {
            $wallet = $this->wallets->walletFor(OwnerType::from($options[0]['owner_type']), $options[0]['owner_id']);
            $this->wallets->debit($wallet, $member, WalletTransactionCategory::SpaceSpecific, $amount, "Booking for space #{$space->id}");

            return [PaymentState::Paid, PaymentSource::Wallet];
        }

        return [PaymentState::Unpaid, null];
    }

    private function assertWithinBusinessHours(Space $space, CarbonInterface $start, CarbonInterface $end): void
    {
        $branch = $space->building->branch;
        $startTime = $this->localTimeOfDay($start);
        $endTime = $this->localTimeOfDay($end);

        foreach ($this->businessHours->periodsFor($start, $branch) as $period) {
            if ($startTime >= $period['open_time'] && $endTime <= $period['close_time']) {
                return;
            }
        }

        throw new ReceptionActionException('api.reception.outside_business_hours');
    }

    private function assertValidGranularity(CarbonInterface $start, int $granularity): void
    {
        $local = $start->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'));
        $minutesSinceMidnight = $local->hour * 60 + $local->minute;

        if ($local->second !== 0 || $minutesSinceMidnight % $granularity !== 0) {
            throw new ReceptionActionException('api.booking.invalid_start_time');
        }
    }

    private function assertValidDuration(CarbonInterface $start, CarbonInterface $end, int $minDuration, int $granularity): void
    {
        $duration = (int) $start->diffInMinutes($end);

        if ($duration < $minDuration) {
            throw new ReceptionActionException('api.booking.duration_too_short');
        }

        if (($duration - $minDuration) % $granularity !== 0) {
            throw new ReceptionActionException('api.booking.duration_invalid_granularity');
        }
    }

    private function assertSlotAvailable(Space $space, CarbonInterface $start, CarbonInterface $end): void
    {
        $overlapping = Booking::query()
            ->where('space_id', $space->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->exists();

        if ($overlapping) {
            throw new ReceptionActionException('api.booking.slot_unavailable');
        }
    }

    /**
     * Present-moment physical-presence count, reusing
     * WalkInCapacityService::start()'s exact counting logic — deliberately
     * not re-solved as a reservation-against-the-future-window check (that's
     * space_capacity_slots, out of scope this phase; see the decision doc).
     */
    private function assertOccupancyLeavesRoom(Space $space): void
    {
        if ($space->capacity === null) {
            return;
        }

        $occupied = Booking::query()
            ->where('space_id', $space->id)
            ->whereNotNull('checked_in_at')
            ->whereNull('checked_out_at')
            ->count();

        $occupied += WalkinSession::query()
            ->where('space_id', $space->id)
            ->whereNull('checked_out_at')
            ->count();

        if ($occupied >= $space->capacity) {
            throw new ReceptionActionException('api.reception.no_capacity');
        }
    }

    /**
     * A gap exactly equal to buffer_minutes passes — inclusive boundary,
     * matching BusinessHoursService's own convention for a sibling concept.
     */
    private function assertBufferRespected(Space $space, CarbonInterface $start, CarbonInterface $end): void
    {
        $buffer = $space->buffer_minutes ?? $this->settings->get('booking.buffer_minutes', 0);

        if ($buffer <= 0) {
            return;
        }

        $violation = Booking::query()
            ->where('space_id', $space->id)
            ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::Pending])
            ->where(function ($query) use ($start, $end, $buffer) {
                $query->where(function ($q) use ($start, $buffer) {
                    $q->where('end_at', '<=', $start)->where('end_at', '>', $start->copy()->subMinutes($buffer));
                })->orWhere(function ($q) use ($end, $buffer) {
                    $q->where('start_at', '>=', $end)->where('start_at', '<', $end->copy()->addMinutes($buffer));
                });
            })
            ->exists();

        if ($violation) {
            throw new ReceptionActionException('api.booking.buffer_conflict');
        }
    }

    private function localTimeOfDay(CarbonInterface $instant): string
    {
        return $instant->copy()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'))->format('H:i');
    }
}
