<?php

namespace Database\Seeders;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\AmountCalculator;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo data for the reception screens — open sessions, closed ones (paid and
 * still owed), end requests with each payment method, an arrival waiting and
 * a booking expected later today — all timed around "now", so the desk, the
 * end-request list and its detail page have something real to show.
 *
 * Local development only, and re-runnable: each run clears the demo members'
 * sessions and bookings and builds them again around the current time.
 *
 * Run manually: php artisan db:seed --class=ReceptionDemoSeeder
 */
class ReceptionDemoSeeder extends Seeder
{
    /** The demo members, by phone: never a real number. */
    private const MEMBERS = [
        ['phone' => '0990000101', 'name' => 'رامي الخطيب', 'points' => 900],
        ['phone' => '0990000102', 'name' => 'لينا حداد', 'points' => 40],
        ['phone' => '0990000103', 'name' => 'سامر النجار', 'points' => 300],
        ['phone' => '0990000104', 'name' => 'هبة السيد', 'points' => 600],
        ['phone' => '0990000105', 'name' => 'يزن العلي', 'points' => 0],
        ['phone' => '0990000106', 'name' => 'نور قباني', 'points' => 250],
        ['phone' => '0990000107', 'name' => 'كريم شامي', 'points' => 150],
        ['phone' => '0990000108', 'name' => 'ريم حلبي', 'points' => 500],
    ];

    public function run(AmountCalculator $amounts, PointConversionService $points, WalletService $wallets): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ReceptionDemoSeeder writes demo members and sessions; it never runs in production.');
        }

        $coSpace = $this->space(SpaceType::CoSpace);
        $room = $this->space(SpaceType::Room);
        $now = CarbonImmutable::now()->utc()->startOfMinute();

        DB::transaction(function () use ($coSpace, $room, $now, $amounts, $points, $wallets) {
            $members = collect(self::MEMBERS)->map(fn (array $row) => $this->member($row, $wallets))->values();

            $this->clear($members->pluck('id')->all());

            [$rami, $lina, $samer, $hiba, $yazan, $nour, $karim, $reem] = $members->all();

            // Open, and asking to end — one per payment method, and one that
            // did not say. Lina's 40 points will not cover her session.
            $this->endRequest($this->openWalkIn($rami, $coSpace, $now->subHours(3)), $now->subMinutes(4), 'wallet');
            $this->endRequest($this->openWalkIn($lina, $coSpace, $now->subHours(2)->subMinutes(30)), $now->subMinutes(9), 'wallet');
            $this->endRequest($this->openWalkIn($samer, $coSpace, $now->subHours(4)), $now->subMinutes(2), 'cash');
            $this->endRequest($this->openWalkIn($hiba, $coSpace, $now->subMinutes(95)), $now->subMinutes(6), 'paymera');
            $this->endRequest($this->openWalkIn($yazan, $coSpace, $now->subHours(1)), $now->subMinute(), null);

            // Open, working away.
            $this->openWalkIn($nour, $coSpace, $now->subMinutes(40));
            Booking::create([
                'space_id' => $room->id,
                'user_id' => $karim->id,
                'start_at' => $now->subHour(),
                'end_at' => $now->addHour(),
                'status' => BookingStatus::Confirmed,
                'checked_in_at' => $now->subHour(),
                'checked_in_via' => SessionStartSource::Reception,
                'price_points' => $amounts->forRange($room, $now->subHour(), $now->addHour()),
                'amount_owed' => $points->pointsToUsd($amounts->forRange($room, $now->subHour(), $now->addHour())),
                'currency' => 'USD',
                'payment_state' => PaymentState::Unpaid,
            ]);

            // Closed earlier today: one settled in cash, one still owed.
            $this->closedWalkIn($reem, $coSpace, $now->subHours(7), $now->subHours(5), paid: true, amounts: $amounts, points: $points);
            $this->closedWalkIn($nour, $coSpace, $now->subHours(9), $now->subHours(8), paid: false, amounts: $amounts, points: $points);

            // Expected later today, and already at the door.
            $expected = Booking::create([
                'space_id' => $room->id,
                'user_id' => $reem->id,
                'start_at' => $now->addHours(2),
                'end_at' => $now->addHours(3),
                'status' => BookingStatus::Confirmed,
                'price_points' => $amounts->forRange($room, $now->addHours(2), $now->addHours(3)),
                'amount_owed' => $points->pointsToUsd($amounts->forRange($room, $now->addHours(2), $now->addHours(3))),
                'currency' => 'USD',
                'payment_state' => PaymentState::Unpaid,
            ]);

            ArrivalRequest::create([
                'user_id' => $reem->id,
                'requested_at' => $now->subMinutes(3),
                'matched_booking_id' => $expected->id,
                'status' => ArrivalRequestStatus::Pending,
            ]);
        });

        $this->command?->info('Reception demo data ready: 6 open sessions (5 asking to end), 1 room booking in progress, 2 closed sessions, 1 arrival waiting.');
    }

    private function space(SpaceType $type): Space
    {
        return Space::query()
            ->where('space_type', $type)
            ->where('status', OperationalStatus::Active)
            ->orderBy('id')
            ->first()
            ?? throw new RuntimeException("ReceptionDemoSeeder needs an active {$type->value} space; run SpaceSeeder first.");
    }

    /** @param array{phone: string, name: string, points: int} $row */
    private function member(array $row, WalletService $wallets): User
    {
        $user = User::firstOrCreate(
            ['phone' => $row['phone']],
            ['name' => $row['name'], 'password' => 'password', 'preferred_language' => 'ar', 'preferred_currency' => 'USD', 'status' => 'active'],
        );
        $user->assignRole('member');

        $wallet = Wallet::firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
        $missing = $row['points'] - $wallets->generalBalance($wallet);

        // Topped back up to the demo balance, whatever earlier runs spent.
        if ($missing > 0) {
            $wallets->creditGeneral($wallet, $missing, WalletTransactionSource::TopUp);
        }

        return $user;
    }

    /** @param list<int> $userIds */
    private function clear(array $userIds): void
    {
        SessionEndRequest::query()->whereIn('user_id', $userIds)->delete();
        ArrivalRequest::query()->whereIn('user_id', $userIds)->delete();
        WalkinSession::query()->whereIn('user_id', $userIds)->delete();
        Booking::query()->whereIn('user_id', $userIds)->doesntHave('payments')->delete();
    }

    private function openWalkIn(User $member, Space $space, CarbonImmutable $since): WalkinSession
    {
        return WalkinSession::create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'checked_in_at' => $since,
            'started_via' => SessionStartSource::Reception,
            'payment_state' => PaymentState::Unpaid,
        ]);
    }

    private function endRequest(WalkinSession $session, CarbonImmutable $at, ?string $method): void
    {
        $session->endRequests()->create([
            'user_id' => $session->user_id,
            'requested_at' => $at,
            'status' => SessionEndRequestStatus::Pending,
            'payment_method' => $method,
        ]);
    }

    private function closedWalkIn(User $member, Space $space, CarbonImmutable $in, CarbonImmutable $out, bool $paid, AmountCalculator $amounts, PointConversionService $points): void
    {
        $price = $amounts->forRange($space, $in, $out);

        WalkinSession::create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'checked_in_at' => $in,
            'checked_out_at' => $out,
            'started_via' => SessionStartSource::Reception,
            'termination_source' => TerminationSource::Reception,
            'price_points' => $price,
            'amount_owed' => $points->pointsToUsd($price),
            'currency' => 'USD',
            'payment_state' => $paid ? PaymentState::Paid : PaymentState::Unpaid,
            'payment_source' => $paid ? PaymentSource::Cash : null,
            'payment_method' => $paid ? PaymentMethod::Cash : null,
            'paid_at' => $paid ? $out : null,
        ]);
    }
}
