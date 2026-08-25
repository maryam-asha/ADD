<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReceptionSessionsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    private function openSpace(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate' => '10.00', 'pricing_currency' => 'USD']);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    public function test_active_sessions_includes_only_checked_in_not_checked_out_rows(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();

        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'status' => BookingStatus::Confirmed,
        ]);
        $walkin = WalkinSession::factory()->create(['space_id' => $space->id]);

        $checkedOutBooking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'status' => BookingStatus::Confirmed,
            'checked_out_at' => now(),
        ]);
        $checkedOutWalkin = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_out_at' => now(),
        ]);
        $pendingBooking = Booking::factory()->pending()->create(['space_id' => $space->id]);

        $response = $this->getJson('/api/v1/admin/reception/sessions/active');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure(['data']);
        $this->assertArrayNotHasKey('meta', $response->json());

        // Booking and WalkinSession have independent id sequences, so a bare
        // 'id' match can collide across the two types — always match on the
        // (type, id) pair together.
        $rows = collect($response->json('data'));
        $bookingRow = $rows->first(fn (array $row) => $row['type'] === 'booking' && $row['id'] === $booking->id);
        $walkinRow = $rows->first(fn (array $row) => $row['type'] === 'walkin' && $row['id'] === $walkin->id);

        $this->assertNotNull($bookingRow);
        $this->assertFalse($bookingRow['is_overdue']);
        $this->assertNotNull($walkinRow);
        $this->assertFalse($walkinRow['is_overdue']);

        $this->assertNull($rows->first(fn (array $row) => $row['type'] === 'booking' && $row['id'] === $checkedOutBooking->id));
        $this->assertNull($rows->first(fn (array $row) => $row['type'] === 'walkin' && $row['id'] === $checkedOutWalkin->id));
        $this->assertNull($rows->first(fn (array $row) => $row['type'] === 'booking' && $row['id'] === $pendingBooking->id));
    }

    public function test_active_sessions_flags_a_session_past_branch_closing_time_as_overdue(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $walkin = WalkinSession::factory()->create(['space_id' => $space->id]);

        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus'));

        $response = $this->getJson('/api/v1/admin/reception/sessions/active');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $walkin->id);
        $response->assertJsonPath('data.0.is_overdue', true);
    }

    public function test_a_member_cannot_access_active_sessions(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/reception/sessions/active')->assertForbidden();
    }
}
