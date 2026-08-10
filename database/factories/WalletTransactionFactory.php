<?php

namespace Database\Factories;

use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WalletTransaction>
 */
class WalletTransactionFactory extends Factory
{
    protected $model = WalletTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wallet_id' => Wallet::factory(),
            'amount' => fake()->randomFloat(2, 5, 100),
            'description' => fake()->sentence(),
            'category' => WalletTransactionCategory::General,
            'restricted_space_id' => null,
            'source' => WalletTransactionSource::TopUp,
            'expires_at' => null,
        ];
    }
}
