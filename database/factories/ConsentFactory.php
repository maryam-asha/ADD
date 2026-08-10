<?php

namespace Database\Factories;

use App\Domain\Identity\Enums\ConsentSubjectType;
use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\Consent;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Consent>
 */
class ConsentFactory extends Factory
{
    protected $model = Consent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => ConsentSubjectType::User,
            'subject_id' => User::factory(),
            'consent_type' => ConsentType::PublicDirectory,
            'granted_at' => now(),
        ];
    }
}
