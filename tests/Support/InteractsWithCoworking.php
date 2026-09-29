<?php

namespace Tests\Support;

use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Services\KioskPairingService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Support\Security\Totp;
use Carbon\CarbonInterface;
use Laravel\Sanctum\Sanctum;

/**
 * Fixtures for the self-service coworking tests
 * (docs/decisions/coworking-self-service-sessions.md). The using test seeds
 * RoleSeeder (and ResourceTypeSeeder where plans with hours are involved).
 */
trait InteractsWithCoworking
{
    /** A coworking space (the factory default type), open 08:00–20:00 every day, in its own branch. */
    protected function coworkingSpace(?int $capacity = 10, int $hourlyRatePoints = 100): Space
    {
        $space = Space::factory()->create([
            'capacity' => $capacity,
            'hourly_rate_points' => $hourlyRatePoints,
        ]);

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    protected function actingAsMember(): User
    {
        return $this->actingAsRole('member');
    }

    protected function actingAsOperations(): User
    {
        return $this->actingAsRole('operations');
    }

    protected function actingAsAdmin(): User
    {
        return $this->actingAsRole('admin');
    }

    /** @return array{0: Kiosk, 1: string} the paired kiosk and its plain secret */
    protected function pairedKiosk(Space $space): array
    {
        $pairings = app(KioskPairingService::class);
        $kiosk = Kiosk::factory()->create(['space_id' => $space->id]);
        $pairing = $pairings->pair($pairings->issueCode($kiosk)->code);

        return [$pairing->kiosk->fresh(), $pairing->secret];
    }

    /** The QR value the kiosk would show $offsetSeconds from now. */
    protected function qrFor(Kiosk $kiosk, string $secret, int $offsetSeconds = 0): string
    {
        return Kiosk::QR_PREFIX.':'.$kiosk->id.':'.app(Totp::class)->codeAt($secret, now()->getTimestamp() + $offsetSeconds);
    }

    /** A plan with a coworking-hours grant. Needs ResourceTypeSeeder (space-hours). */
    protected function planWithHours(float $hours, ?string $windowStart = '08:00', ?string $windowEnd = '20:00'): Plan
    {
        $plan = Plan::factory()->create([
            'access_window_start' => $windowStart,
            'access_window_end' => $windowEnd,
        ]);

        $plan->resourceGrants()->create([
            'resource_type_id' => ResourceType::where('slug', PlanHourCoverageService::RESOURCE_TYPE_SLUG)->value('id'),
            'quantity' => $hours,
            'order' => 0,
        ]);

        return $plan;
    }

    protected function subscribe(User $member, Plan $plan, ?CarbonInterface $periodEnd = null): Membership
    {
        return Membership::create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => $periodEnd ?? now()->addDays(20),
        ]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
