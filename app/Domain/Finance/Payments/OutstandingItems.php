<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything one member still owes, for the reception desk to settle in one
 * visit: unpaid bookings in a payable status (never cancelled, rejected or a
 * no-show — docs/decisions/booking-outcomes-and-extension.md), closed unpaid
 * coworking sessions, approved unpaid registrations for paid events, and
 * plans they chose to pay for in cash whose hold is still open.
 *
 * Each item names what POST admin/reception/payments/settle takes to settle
 * it (`purpose` plus `payable_id`, or `payment_id` for a held plan) and what
 * it costs, in points and in `$currency`.
 */
class OutstandingItems
{
    public function __construct(private readonly ReceivedMoneyConverter $converter) {}

    /**
     * @return array{items: list<array<string, mixed>>, total_points: int, total_amount: array{value: string, currency: string}}
     */
    public function for(int $userId, string $currency): array
    {
        $items = [
            ...$this->bookings($userId),
            ...$this->sessions($userId),
            ...$this->registrations($userId),
            ...$this->heldPlans($userId),
        ];

        foreach ($items as &$item) {
            $item['amount'] = ['value' => $this->converter->pointsToAmount($item['amount_points'], $currency), 'currency' => $currency];
        }
        unset($item);

        $total = (int) array_sum(array_column($items, 'amount_points'));

        return [
            'items' => $items,
            'total_points' => $total,
            'total_amount' => ['value' => $this->converter->pointsToAmount($total, $currency), 'currency' => $currency],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bookings(int $userId): array
    {
        return $this->bookingsOwed()
            ->with('space')
            ->where('user_id', $userId)
            ->orderBy('start_at')
            ->get()
            ->map(fn (Booking $b) => [
                'purpose' => PaymentPurpose::Booking->value,
                'payable_id' => $b->id,
                'title' => $b->space?->translate('name'),
                'starts_at' => $b->start_at?->toIso8601String(),
                'amount_points' => (int) $b->price_points,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sessions(int $userId): array
    {
        return $this->sessionsOwed()
            ->with('space')
            ->where('user_id', $userId)
            ->orderBy('checked_in_at')
            ->get()
            ->map(fn (WalkinSession $s) => [
                'purpose' => PaymentPurpose::CoworkingSession->value,
                'payable_id' => $s->id,
                'title' => $s->space?->translate('name'),
                'starts_at' => $s->checked_in_at?->toIso8601String(),
                'amount_points' => (int) $s->price_points,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registrations(int $userId): array
    {
        return $this->registrationsOwed()
            ->with('event')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (EventAttendee $a) => [
                'purpose' => PaymentPurpose::EventRegistration->value,
                'payable_id' => $a->id,
                'title' => $a->event?->translate('title'),
                'starts_at' => $a->event?->start_at?->toIso8601String(),
                'amount_points' => (int) $a->price_points,
            ])
            ->all();
    }

    /**
     * Held for the member who chose cash — for themselves, or for a company
     * they buy for.
     *
     * @return list<array<string, mixed>>
     */
    private function heldPlans(int $userId): array
    {
        return $this->plansHeld()
            ->with('plan')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (Payment $p) => [
                'purpose' => PaymentPurpose::PlanPurchase->value,
                'payment_id' => $p->id,
                'title' => $p->plan?->translate('name'),
                'starts_at' => null,
                // The plan's current price: what the desk will charge.
                'amount_points' => (int) ($p->plan?->price_points ?? $p->amount_points),
            ])
            ->all();
    }

    /**
     * How many items are owed across every member — the reception desk's
     * "pending payments" — by the same rules for() lists one member's by.
     */
    public function countAll(): int
    {
        return $this->bookingsOwed()->count()
            + $this->sessionsOwed()->count()
            + $this->registrationsOwed()->count()
            + $this->plansHeld()->count();
    }

    /** @return Builder<Booking> */
    private function bookingsOwed(): Builder
    {
        return Booking::query()
            ->whereIn('status', BookingStatus::payable())
            ->where('payment_state', PaymentState::Unpaid)
            ->where('price_points', '>', 0);
    }

    /** @return Builder<WalkinSession> */
    private function sessionsOwed(): Builder
    {
        return WalkinSession::query()
            ->whereNotNull('checked_out_at')
            ->where('payment_state', PaymentState::Unpaid)
            ->where('price_points', '>', 0);
    }

    /** @return Builder<EventAttendee> */
    private function registrationsOwed(): Builder
    {
        return EventAttendee::query()
            ->where('status', EventRegistrationStatus::Approved)
            ->where('payment_state', EventPaymentState::Unpaid)
            ->where('price_points', '>', 0);
    }

    /** @return Builder<Payment> */
    private function plansHeld(): Builder
    {
        return Payment::query()
            ->where('method', CashMethod::KEY)
            ->where('purpose', PaymentPurpose::PlanPurchase)
            ->where('status', PaymentStatus::Pending)
            ->where('expires_at', '>', now());
    }
}
