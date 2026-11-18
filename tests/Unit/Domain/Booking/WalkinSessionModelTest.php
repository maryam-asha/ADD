<?php

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalkinSessionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_walkin_session_can_be_created_with_defaults(): void
    {
        $space = Space::factory()->room()->create();
        $member = User::factory()->create();

        $session = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
        ]);

        $this->assertSame(PaymentState::Unpaid, $session->payment_state);
        $this->assertNotNull($session->checked_in_at);
        $this->assertNull($session->checked_out_at);
        $this->assertTrue($session->space->is($space));
        $this->assertTrue($session->user->is($member));
    }
}
