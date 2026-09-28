<?php

namespace Tests\Support;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
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

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }
}
