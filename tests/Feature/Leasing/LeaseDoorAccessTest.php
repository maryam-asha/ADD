<?php

namespace Tests\Feature\Leasing;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Models\OfficeLease;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

class LeaseDoorAccessTest extends LeasingTestCase
{
    private function lease(array $attributes = []): OfficeLease
    {
        return OfficeLease::factory()->create([
            'space_id' => $this->leasableOffice()->id,
            'starts_on' => now()->toDateString(),
            'duration_months' => 3,
            'billing_cycle_months' => 3,
            'ends_on' => now()->addMonthsNoOverflow(3)->subDay()->toDateString(),
            ...$attributes,
        ]);
    }

    public function test_a_lease_grant_is_active_at_once_and_ends_with_the_lease(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease();

        $grant = app(PasscodeIssuanceService::class)->issueForLease($lease);

        $this->assertSame(AccessGrantStatus::Activated, $grant->status);
        $this->assertSame(AccessSourceType::Tenancy, $grant->source_type);
        $this->assertSame($lease->id, $grant->office_lease_id);
        $this->assertSame($lease->company_id, $grant->grantee_id);
        $this->assertSame($lease->ends_on->endOfDay()->timestamp, $grant->expires_at->timestamp);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/keyboardPwd/get')
            && (int) $request['endDate'] === $lease->ends_on->endOfDay()->getTimestamp() * 1000);
    }

    public function test_expiring_unactivated_grants_leaves_lease_grants_alone(): void
    {
        $this->fakeTtlock();
        $grant = app(PasscodeIssuanceService::class)->issueForLease($this->lease());
        $this->travel(2)->days();

        app(PasscodeIssuanceService::class)->expireOverdue();

        $this->assertSame(AccessGrantStatus::Activated, $grant->fresh()->status);
    }

    public function test_a_member_with_door_access_opens_the_office_and_one_without_cannot(): void
    {
        $this->fakeTtlock();
        Http::fake(['api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200)]);
        $lease = $this->lease();
        app(PasscodeIssuanceService::class)->issueForLease($lease);
        $lock = $lease->space->devices()->where('type', 'lock')->first();
        $lock->update(['qr_value' => 'office-sticker']);

        $allowed = User::factory()->create();
        $allowed->assignRole('member');
        $lease->company->members()->attach($allowed->id, ['door_access_enabled' => true]);
        $refused = User::factory()->create();
        $refused->assignRole('member');
        $lease->company->members()->attach($refused->id, ['door_access_enabled' => false]);

        Sanctum::actingAs($allowed, ['member-app']);
        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'office-sticker'])->assertOk();

        Sanctum::actingAs($refused, ['member-app']);
        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'office-sticker'])->assertForbidden();
    }

    public function test_revoking_a_lease_deletes_its_passcode(): void
    {
        $this->fakeTtlock();
        $lease = $this->lease();
        $grant = app(PasscodeIssuanceService::class)->issueForLease($lease);

        app(PasscodeIssuanceService::class)->revokeForLease($lease);

        $this->assertSame(AccessGrantStatus::Revoked, $grant->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v3/keyboardPwd/delete'));
    }

    public function test_has_lock(): void
    {
        $issuance = app(PasscodeIssuanceService::class);

        $this->assertTrue($issuance->hasLock($this->leasableOffice()));
        $this->assertFalse($issuance->hasLock($this->leasableOffice(withLock: false)));
    }
}
