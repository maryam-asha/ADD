<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\KioskCodeVerifier;
use App\Domain\Booking\Services\KioskPairingService;
use App\Support\Security\Totp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class KioskCodeVerifierTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private KioskCodeVerifier $codes;

    protected function setUp(): void
    {
        parent::setUp();
        // 07:00:00 UTC — exactly on a 30 s step boundary.
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->codes = app(KioskCodeVerifier::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assertRefused(string $qrValue): void
    {
        try {
            $this->codes->verify($qrValue);
            $this->fail("Accepted {$qrValue}");
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.coworking.invalid_code', $e->messageKey);
            $this->assertSame(422, $e->status);
        }
    }

    public function test_a_code_is_accepted_for_its_step_and_one_either_side(): void
    {
        [$kiosk, $secret] = $this->pairedKiosk($this->coworkingSpace());

        foreach ([0, 30, -30] as $offset) {
            $this->assertTrue($this->codes->verify($this->qrFor($kiosk, $secret, $offset))->is($kiosk));
        }
    }

    public function test_a_code_two_steps_away_is_refused(): void
    {
        [$kiosk, $secret] = $this->pairedKiosk($this->coworkingSpace());

        $this->assertRefused($this->qrFor($kiosk, $secret, 60));
        $this->assertRefused($this->qrFor($kiosk, $secret, -60));
    }

    public function test_another_kiosks_code_is_refused(): void
    {
        $space = $this->coworkingSpace();
        [$a] = $this->pairedKiosk($space);
        [, $secretB] = $this->pairedKiosk($space);

        $this->assertRefused(Kiosk::QR_PREFIX.':'.$a->id.':'.app(Totp::class)->codeAt($secretB, now()->getTimestamp()));
    }

    public function test_an_inactive_or_revoked_kiosk_is_refused(): void
    {
        $space = $this->coworkingSpace();
        [$inactive, $secretI] = $this->pairedKiosk($space);
        [$revoked, $secretR] = $this->pairedKiosk($space);
        $inactive->update(['is_active' => false]);
        app(KioskPairingService::class)->revoke($revoked);

        $this->assertRefused($this->qrFor($inactive, $secretI));
        $this->assertRefused($this->qrFor($revoked, $secretR));
    }

    public function test_malformed_values_are_refused(): void
    {
        [$kiosk] = $this->pairedKiosk($this->coworkingSpace());

        $this->assertRefused('hello');
        $this->assertRefused("add-cowork:v1:{$kiosk->id}:abcdefgh");
        $this->assertRefused("add-cowork:v2:{$kiosk->id}:12345678");
        $this->assertRefused('add-cowork:v1:999999:12345678');
    }

    public function test_the_same_code_serves_several_members_and_marks_the_kiosk_used(): void
    {
        [$kiosk, $secret] = $this->pairedKiosk($this->coworkingSpace());
        $qr = $this->qrFor($kiosk, $secret);

        $this->codes->verify($qr);
        $this->codes->verify($qr);

        $this->assertTrue($kiosk->fresh()->last_used_at->equalTo(now()));
    }
}
