<?php

namespace Tests\Feature\Notification;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one-off rewrite of notices stored before every notice named its
 * object as target_type/target_id (2026_11_08_100000): each old shape lands
 * on the target its sender would name today, and nothing else is touched.
 */
class ConvertNotificationTargetsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_every_old_shape_becomes_its_target(): void
    {
        $attendee = EventAttendee::factory()->create();

        $cases = [
            'walk-in session' => [['session_type' => 'walkin', 'session_id' => '12'], ['target_type' => 'walkin_session', 'target_id' => '12']],
            'booking session' => [['session_type' => 'booking', 'session_id' => '81'], ['target_type' => 'booking', 'target_id' => '81']],
            'arrival request' => [['arrival_request_id' => '5'], ['target_type' => 'arrival_request', 'target_id' => '5']],
            'top-up payment' => [['payment_id' => 23, 'purpose' => 'wallet_top_up', 'payable_type' => null, 'payable_id' => null], ['target_type' => 'payment', 'target_id' => '23']],
            'booking payment' => [['payment_id' => 24, 'purpose' => 'booking', 'payable_type' => 'booking', 'payable_id' => 81], ['target_type' => 'booking', 'target_id' => '81']],
            'session payment' => [['payment_id' => 25, 'purpose' => 'coworking_session', 'payable_type' => 'walkin_session', 'payable_id' => 12], ['target_type' => 'walkin_session', 'target_id' => '12']],
            'sale payment' => [['payment_id' => 26, 'purpose' => 'item_sale', 'payable_type' => 'item_sale', 'payable_id' => 3], ['target_type' => 'item_sale', 'target_id' => '3']],
            'class-named payable' => [['payment_id' => 27, 'purpose' => 'booking', 'payable_type' => 'App\Domain\Booking\Models\Booking', 'payable_id' => 82], ['target_type' => 'booking', 'target_id' => '82']],
            'registration payment' => [['payment_id' => 28, 'purpose' => 'event_registration', 'payable_type' => 'event_attendee', 'payable_id' => $attendee->id], ['target_type' => 'event', 'target_id' => (string) $attendee->event_id]],
            'event payment due' => [['purpose' => 'event_registration', 'payable_type' => 'event_attendee', 'payable_id' => 999, 'event_id' => 7], ['target_type' => 'event', 'target_id' => '7']],
            'inquiry' => [['inquiry_id' => 4], ['target_type' => 'inquiry', 'target_id' => '4']],
            'desk needed' => [['membership_id' => 9], ['target_type' => 'membership', 'target_id' => '9']],
        ];

        $ids = [];
        foreach ($cases as $name => [$old]) {
            $ids[$name] = $this->storeNotice($old);
        }

        $this->runMigration();

        foreach ($cases as $name => [, $expected]) {
            $this->assertSame($expected, $this->storedData($ids[$name]), $name);
        }
    }

    public function test_it_keeps_what_it_does_not_know_and_what_is_already_converted(): void
    {
        $alreadyTarget = $this->storeNotice(['target_type' => 'company', 'target_id' => '3']);
        $unknownKeys = $this->storeNotice(['session_type' => 'booking', 'session_id' => '81', 'extra' => 'kept']);
        $noData = $this->storeNotice(null);
        $before = DB::table('notifications')->where('id', $alreadyTarget)->value('data');

        $this->runMigration();

        $this->assertSame($before, DB::table('notifications')->where('id', $alreadyTarget)->value('data'));
        $this->assertSame(['target_type' => 'booking', 'target_id' => '81', 'extra' => 'kept'], $this->storedData($unknownKeys));
        $this->assertNull($this->storedData($noData));
    }

    public function test_the_rest_of_the_stored_notice_is_untouched(): void
    {
        $id = $this->storeNotice(['payment_id' => 23, 'purpose' => 'wallet_top_up', 'payable_type' => null, 'payable_id' => null]);

        $this->runMigration();

        $payload = json_decode(DB::table('notifications')->where('id', $id)->value('data'), true);
        $this->assertSame('payment.received', $payload['template_key']);
        $this->assertSame(['points' => 500], $payload['params']);
        $this->assertSame('member', $payload['audience']);
    }

    /** @param array<string, mixed>|null $data */
    private function storeNotice(?array $data): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\Notifications\GeneralNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $this->user->id,
            'data' => json_encode(['template_key' => 'payment.received', 'params' => ['points' => 500], 'data' => $data, 'audience' => 'member']),
            'audience' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function storedData(string $id): ?array
    {
        return json_decode(DB::table('notifications')->where('id', $id)->value('data'), true)['data'];
    }

    private function runMigration(): void
    {
        (require database_path('migrations/2026_11_08_100000_convert_notification_data_to_targets.php'))->up();
    }
}
