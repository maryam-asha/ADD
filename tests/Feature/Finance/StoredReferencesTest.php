<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Models\Payment;
use App\Domain\Membership\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Wallet rows, walk-ins, event registrations and payments carry a stored,
 * human-readable number, as bookings do (BackfillBookingReferencesMigrationTest):
 * one series per prefix and year, rows made before the column existed
 * numbered in the order they were made, and new rows carrying on from there.
 */
class StoredReferencesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string<Model>, string, string, string}>
     */
    public static function tables(): array
    {
        return [
            'wallet transactions' => [WalletTransaction::class, 'wallet_transactions', 'TRX', '2026_10_08_100000_add_reference_to_wallet_transactions_table.php'],
            'walk-in sessions' => [WalkinSession::class, 'walkin_sessions', 'WS', '2026_10_08_100100_add_reference_to_walkin_sessions_table.php'],
            'event attendees' => [EventAttendee::class, 'event_attendees', 'EVT', '2026_10_08_100200_add_reference_to_event_attendees_table.php'],
            'payments' => [Payment::class, 'payments', 'PAY', '2026_10_08_100300_add_reference_to_payments_table.php'],
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('tables')]
    public function test_a_new_row_is_numbered_in_its_own_yearly_series(string $model, string $table, string $prefix): void
    {
        $year = now()->format('Y');

        $first = $model::factory()->create();
        $second = $model::factory()->create();

        $this->assertSame("{$prefix}-{$year}-00001", $first->reference);
        $this->assertSame("{$prefix}-{$year}-00002", $second->reference);
        $this->assertSame($second->reference, DB::table($table)->where('id', $second->id)->value('reference'));
    }

    /**
     * @param  class-string<Model>  $model
     */
    #[DataProvider('tables')]
    public function test_existing_rows_are_backfilled_in_order_and_new_ones_carry_on(string $model, string $table, string $prefix, string $file): void
    {
        $year = now()->format('Y');
        $later = $model::factory()->create();
        $earlier = $model::factory()->create();
        $lastYear = $model::factory()->create();

        DB::table($table)->where('id', $later->id)->update(['created_at' => "{$year}-03-01 09:00:00"]);
        DB::table($table)->where('id', $earlier->id)->update(['created_at' => "{$year}-01-05 09:00:00"]);
        DB::table($table)->where('id', $lastYear->id)->update(['created_at' => ($year - 1).'-12-31 23:00:00']);

        $migration = require database_path("migrations/{$file}");
        $migration->down();
        $migration->up();

        $this->assertSame("{$prefix}-{$year}-00001", DB::table($table)->where('id', $earlier->id)->value('reference'));
        $this->assertSame("{$prefix}-{$year}-00002", DB::table($table)->where('id', $later->id)->value('reference'));
        $this->assertSame("{$prefix}-".($year - 1).'-00001', DB::table($table)->where('id', $lastYear->id)->value('reference'));
        $this->assertSame("{$prefix}-{$year}-00003", $model::factory()->create()->reference);
    }
}
