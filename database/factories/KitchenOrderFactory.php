<?php

namespace Database\Factories;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Kitchen\Enums\DeliveryType;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Enums\OrderSource;
use App\Domain\Kitchen\Models\KitchenOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending pickup order with no lines, billed to a fresh walk-in coworking
 * session of its own member, from a kitchen store in that session's branch.
 *
 * @extends Factory<KitchenOrder>
 */
class KitchenOrderFactory extends Factory
{
    protected $model = KitchenOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $session = WalkinSession::factory()->create(['space_id' => Space::factory()]);
        $branchId = $session->space->building->branch_id;

        return [
            'user_id' => $session->user_id,
            'branch_id' => $branchId,
            'warehouse_id' => Warehouse::factory()->state(['place_type' => 'branch', 'place_id' => $branchId]),
            'billable_type' => $session->getMorphClass(),
            'billable_id' => $session->id,
            'delivery_type' => DeliveryType::Pickup,
            'source' => OrderSource::App,
            'status' => KitchenOrderStatus::Pending,
            'total_points' => 0,
            'reached_kitchen_at' => now(),
        ];
    }

    /** An order that stands on no bill (paid at send, or waiting for reception). */
    public function withoutBill(): static
    {
        return $this->state(fn () => ['billable_type' => null, 'billable_id' => null]);
    }
}
