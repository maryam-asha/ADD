<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Membership\Enums\WalletTransactionSource;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_booking_status_cases(): void
    {
        $this->assertSame(['confirmed', 'cancelled'], array_column(BookingStatus::cases(), 'value'));
    }

    public function test_payment_state_cases(): void
    {
        $this->assertSame(['paid', 'unpaid'], array_column(PaymentState::cases(), 'value'));
    }

    public function test_payment_source_cases(): void
    {
        $this->assertSame(['wallet', 'cash'], array_column(PaymentSource::cases(), 'value'));
    }

    public function test_termination_source_cases(): void
    {
        $this->assertSame(['reception', 'auto'], array_column(TerminationSource::cases(), 'value'));
    }

    public function test_payment_method_cases(): void
    {
        $this->assertSame(['cash', 'sham', 'mtn', 'syriatel'], array_column(PaymentMethod::cases(), 'value'));
    }

    public function test_wallet_transaction_source_gained_a_refund_case(): void
    {
        $this->assertSame('refund', WalletTransactionSource::Refund->value);
    }
}
