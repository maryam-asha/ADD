<?php

namespace Database\Factories;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Enums\PasscodeType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Models\Device;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessGrant>
 */
class AccessGrantFactory extends Factory
{
    protected $model = AccessGrant::class;

    public function definition(): array
    {
        $issuedAt = now();

        return [
            'lock_id' => Device::factory(),
            'grantee_type' => OwnerType::User,
            'grantee_id' => User::factory(),
            'source_type' => AccessSourceType::Booking,
            'source_id' => null,
            'allocation_model' => AllocationModel::BookingHourly,
            'passcode_type' => PasscodeType::Period,
            'passcode_value' => (string) random_int(100000, 999999),
            'vendor_keyboard_pwd_id' => $this->faker->unique()->numberBetween(1000, 999999),
            'issued_at' => $issuedAt,
            'must_activate_by' => $issuedAt->copy()->addHours(24),
            'activated_at' => null,
            'expires_at' => $issuedAt->copy()->addHours(4),
            'status' => AccessGrantStatus::Issued,
        ];
    }

    public function activated(): static
    {
        return $this->state(fn () => [
            'status' => AccessGrantStatus::Activated,
            'activated_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => AccessGrantStatus::Revoked]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['status' => AccessGrantStatus::Expired]);
    }

    public function forCompany(): static
    {
        return $this->state(fn () => [
            'grantee_type' => OwnerType::Company,
            'grantee_id' => Company::factory(),
            'source_type' => AccessSourceType::Tenancy,
            'allocation_model' => AllocationModel::Tenancy,
            'expires_at' => null,
        ]);
    }
}
