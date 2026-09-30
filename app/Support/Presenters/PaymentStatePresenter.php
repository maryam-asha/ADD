<?php

namespace App\Support\Presenters;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Support\Enums\EventPaymentState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The payment block every payable shows the member, in one shape:
 *
 *   {state, method, amount_points, price, price_point, paid_at, payment_id}
 *
 * `state` is `paid`, `unpaid`, `pending` (a card payment in flight, or a
 * plan held for cash), or null when there is nothing to pay — a free event,
 * a session still running, a session the plan covered in full. `method` is
 * how it was paid (or, for a registration or a hold, how the member chose
 * to pay). `payment_id` is the latest payment made for it, for polling.
 */
class PaymentStatePresenter
{
    public function __construct(private readonly PriceLabelPresenter $prices) {}

    /**
     * @return array{state: string|null, method: string|null, amount_points: int|null, price: string|null, price_point: string|null, paid_at: string|null, payment_id: int|null}
     */
    public function for(Model $payable, ?User $viewer = null): array
    {
        [$state, $method, $points, $paidAt] = match (true) {
            $payable instanceof Payment => $this->hold($payable),
            $payable instanceof Booking, $payable instanceof WalkinSession => $this->session($payable),
            $payable instanceof EventAttendee => $this->registration($payable),
            default => [null, null, null, null],
        };

        $latest = match (true) {
            $payable instanceof Payment => $payable,
            // Eager-loaded by a list, so it does not query once per row.
            $payable->relationLoaded('payments') => $payable->getRelation('payments')->sortByDesc('id')->first(),
            default => Payment::query()->forPayable($payable)->latest('id')->first(),
        };

        // Unpaid, but a card payment for it is open: the member is paying.
        if ($state === 'unpaid' && $latest?->status === PaymentStatus::Pending) {
            $state = 'pending';
        }

        $labels = $points === null || $points <= 0
            ? ['price' => null, 'price_point' => null]
            : $this->prices->total($points, $viewer);

        return [
            'state' => $state,
            'method' => $method,
            'amount_points' => $points,
            'price' => $labels['price'],
            'price_point' => $labels['price_point'],
            'paid_at' => $paidAt?->toIso8601String(),
            'payment_id' => $latest?->id,
        ];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null, 3: CarbonInterface|null}
     */
    private function session(Booking|WalkinSession $session): array
    {
        if ($session->payment_state === PaymentState::Paid) {
            return ['paid', $session->payment_source?->value, $session->price_points, $session->paid_at];
        }

        // A walk-in is priced only when it closes.
        if ($session instanceof WalkinSession && $session->checked_out_at === null) {
            return [null, null, null, null];
        }

        $points = (int) ($session->price_points ?? 0);

        return $points > 0 ? ['unpaid', null, $points, null] : [null, null, null, null];
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null, 3: CarbonInterface|null}
     */
    private function registration(EventAttendee $attendee): array
    {
        return match ($attendee->payment_state) {
            null => [null, null, null, null],
            EventPaymentState::Paid => ['paid', $attendee->payment_method, $attendee->price_points, $attendee->paid_at],
            EventPaymentState::Unpaid => ['unpaid', $attendee->payment_method, $attendee->price_points, null],
        };
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: int|null, 3: CarbonInterface|null}
     */
    private function hold(Payment $payment): array
    {
        return match ($payment->status) {
            PaymentStatus::Pending => ['pending', $payment->method, $payment->amount_points, null],
            PaymentStatus::Accepted => ['paid', $payment->method, $payment->amount_points, $payment->settled_at],
            default => [null, $payment->method, $payment->amount_points, null],
        };
    }
}
