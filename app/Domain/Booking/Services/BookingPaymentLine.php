<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Support\Collection;

/**
 * The sentence a booking confirmation ends with: how it was paid, or how it
 * will be — points taken from the member's (or their company's) wallet,
 * paid by card, paid in cash at the desk, to be paid at the desk, covered
 * by the plan, or still owed. A free booking has none.
 *
 * Returned as a translatable param (`['ar' => ' …', 'en' => ' …']`, with
 * its leading space, or empty), so each reader sees it in their language
 * (NotificationText). Read from what was saved, so it must run after the
 * booking's transaction commits — the desk's cash is recorded after the
 * booking is made, in the same transaction.
 */
class BookingPaymentLine
{
    public function __construct(private readonly PaymentMethodRegistry $methods) {}

    /** @param  iterable<Booking>  $bookings  one booking, or every booking of a batch */
    public function for(iterable $bookings): array
    {
        $bookings = collect($bookings);
        $paid = $bookings->flatMap(fn (Booking $booking) => $this->paymentsFor($booking));

        if ($paid->isNotEmpty()) {
            return $this->fromPayment($paid->first(), (int) $paid->sum('amount_points'));
        }

        $owed = (int) $bookings->sum(fn (Booking $booking) => (int) $booking->price_points);

        if ($owed === 0) {
            return $bookings->contains(fn (Booking $booking) => $booking->payment_source === PaymentSource::Plan)
                ? $this->line('plan')
                : $this->none();
        }

        $method = $bookings->first()->chosen_payment_method;

        return $this->line($method === CashMethod::KEY ? 'cash_due' : 'due', ['points' => $owed]);
    }

    /**
     * The sentence an extension notice ends with, read once the extension
     * is saved: the difference taken from the wallet the member chose when
     * the booking is still paid (BookingExtensionService charged it), else
     * what the booking now owes — at the desk when cash was chosen,
     * otherwise in the app or at the desk. An extension that cost nothing
     * has none.
     */
    public function forExtension(Booking $booking, int $differencePoints): array
    {
        if ($differencePoints <= 0) {
            return $this->none();
        }

        if ($booking->payment_state === PaymentState::Paid) {
            $paid = $booking->payments()
                ->with('wallet')
                ->where('purpose', PaymentPurpose::BookingExtension)
                ->where('status', PaymentStatus::Accepted)
                ->latest('id')
                ->first();

            return $paid !== null
                ? $this->fromPayment($paid, $differencePoints)
                : $this->line('wallet', ['points' => $differencePoints]);
        }

        $owed = max(0, (int) $booking->price_points - $booking->pointsPaid());

        return $this->line($booking->chosen_payment_method === CashMethod::KEY ? 'cash_due' : 'due', ['points' => $owed]);
    }

    /** @return Collection<int, Payment> the booking's own payments that went through — not its extensions' */
    private function paymentsFor(Booking $booking): Collection
    {
        return $booking->payments()
            ->with('wallet')
            ->where('purpose', PaymentPurpose::Booking)
            ->where('status', PaymentStatus::Accepted)
            ->whereNotNull('settled_at')
            ->whereNull('refunded_at')
            ->orderBy('id')
            ->get();
    }

    private function fromPayment(Payment $payment, int $points): array
    {
        if ($payment->method === WalletMethod::KEY) {
            $wallet = $payment->wallet;

            if ($wallet?->owner_type === OwnerType::Company && ($company = Company::find($wallet->owner_id)) !== null) {
                return $this->line('company_wallet', ['points' => $points, 'company' => (string) $company->legal_name]);
            }

            return $this->line('wallet', ['points' => $points]);
        }

        if (in_array($payment->method, $this->methods->gatewayKeys(), true)) {
            return $this->line('card', ['points' => $points]);
        }

        return $this->line('cash_paid', ['points' => $points]);
    }

    /** @param  array<string, mixed>  $replace */
    private function line(string $case, array $replace = []): array
    {
        $lines = [];

        foreach (SetLocaleFromHeader::SUPPORTED_LOCALES as $locale) {
            $lines[$locale] = ' '.__("notifications.booking.payment_lines.{$case}", $replace, $locale);
        }

        return $lines;
    }

    private function none(): array
    {
        return array_fill_keys(SetLocaleFromHeader::SUPPORTED_LOCALES, '');
    }
}
