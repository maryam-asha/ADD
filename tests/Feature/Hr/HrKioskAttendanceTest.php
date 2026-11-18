<?php

namespace Tests\Feature\Hr;

use Add\Hr\Time\Models\AttendanceDay;
use Add\Hr\Time\Models\Punch;
use Add\Hr\Time\Services\AttendanceCards;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Hr\Kiosk\KioskRequestSignature;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * Staff cards scanned at a reception kiosk become attendance punches, like
 * a fingerprint clock. The kiosk signs each request with its pairing
 * secret; times are Damascus local (ADD's app.timezone setting).
 */
class HrKioskAttendanceTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private Kiosk $kiosk;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 2 March 2026, 10:00 in Damascus.
        $this->travelTo('2026-03-02 07:00:00');

        $this->kiosk = Kiosk::factory()->create();
        $this->kiosk->forceFill(['totp_secret' => self::SECRET, 'paired_at' => now()])->save();
    }

    /**
     * @param  list<array<string, string>>  $punches
     * @param  array<string, string>  $overrideHeaders
     */
    private function send(array $punches, array $overrideHeaders = [], string $secret = self::SECRET): TestResponse
    {
        $body = ['punches' => $punches];
        $headers = KioskRequestSignature::headersFor($this->kiosk, $secret, json_encode($body));

        return $this->postJson('/api/v1/kiosk/attendance/punches', $body, [...$headers, ...$overrideHeaders]);
    }

    private function scan(string $card, string $at = '2026-03-02T09:58:00+03:00', ?string $id = null): array
    {
        return ['id' => $id ?? (string) Str::uuid(), 'card' => $card, 'scanned_at' => $at];
    }

    public function test_a_scanned_staff_card_becomes_a_punch_and_greets_the_employee(): void
    {
        $employee = $this->hireFor(User::factory()->create(['name' => 'لينا']));
        $card = app(AttendanceCards::class)->issue($employee, null);
        $this->assertStringStartsWith('add-staff:v1:', $card);

        $this->send([$this->scan($card)])
            ->assertOk()
            ->assertJsonPath('data.0.status', 'recorded')
            ->assertJsonPath('data.0.employee.id', $employee->id)
            ->assertJsonPath('data.0.employee.name.ar', 'لينا');

        $punch = Punch::query()->sole();
        $this->assertSame('kiosk', $punch->device_type);
        $this->assertSame((string) $this->kiosk->id, $punch->device_id);
        $this->assertSame('2026-03-02 06:58:00', $punch->punched_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-02', AttendanceDay::query()->sole()->work_date->toDateString());
        $this->assertNotNull($this->kiosk->refresh()->last_used_at);
    }

    public function test_an_offline_queue_is_accepted_once_and_bad_scans_are_reported_one_by_one(): void
    {
        $employee = $this->hireFor(User::factory()->create());
        $card = app(AttendanceCards::class)->issue($employee, null);
        $queued = $this->scan($card, '2026-03-01T08:00:00+03:00');

        $this->send([
            $queued,
            $this->scan('add-staff:v1:not-a-card'),
            $this->scan($card, '2026-02-20T08:00:00+03:00'),
        ])->assertOk()
            ->assertJsonPath('data.0.status', 'recorded')
            ->assertJsonPath('data.1.status', 'unknown_card')
            ->assertJsonPath('data.1.employee', null)
            ->assertJsonPath('data.2.status', 'rejected');

        $this->send([$queued])->assertOk()->assertJsonPath('data.0.status', 'duplicate');
        $this->assertSame(1, Punch::query()->count());
    }

    public function test_only_a_paired_active_kiosk_with_a_fresh_signature_is_heard(): void
    {
        $scan = [$this->scan('add-staff:v1:x')];

        $this->send($scan, secret: 'WRONGSECRETWRONGSECRET')->assertUnauthorized();
        $this->send($scan, ['X-Kiosk-Timestamp' => (string) (time() - 600)])->assertUnauthorized();
        $this->postJson('/api/v1/kiosk/attendance/punches', ['punches' => $scan])->assertUnauthorized();

        $this->kiosk->forceFill(['is_active' => false])->save();
        $this->send($scan)->assertUnauthorized()->assertJsonPath('message', __('api.hr.kiosk_unauthorized'));

        $this->assertSame(0, Punch::query()->count());
    }

    public function test_a_signed_but_malformed_batch_is_a_validation_error(): void
    {
        $this->send([['id' => 'not-a-uuid', 'card' => 'x', 'scanned_at' => 'yesterday-ish']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['punches.0.id', 'punches.0.scanned_at']);
    }

    public function test_only_the_signed_body_is_read_never_the_query_string(): void
    {
        $owner = $this->hireFor(User::factory()->create());
        $signed = app(AttendanceCards::class)->issue($owner, null);
        $injected = app(AttendanceCards::class)->issue($this->hireFor(User::factory()->create()), null);

        // A captured request replayed as text/plain with punches added in the URL.
        $body = json_encode(['punches' => [$this->scan($signed)]]);
        $query = http_build_query(['punches' => [$this->scan($injected)]]);
        $this->call('POST', "/api/v1/kiosk/attendance/punches?{$query}", [], [], [], [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_ACCEPT' => 'application/json',
            ...collect(KioskRequestSignature::headersFor($this->kiosk, self::SECRET, $body))
                ->mapWithKeys(fn ($value, $name) => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])->all(),
        ], $body)->assertOk();

        $this->assertSame([$owner->id], Punch::query()->pluck('employee_id')->all());
    }
}
