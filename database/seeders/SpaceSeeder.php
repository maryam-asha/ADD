<?php

namespace Database\Seeders;

use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\ResourceCategory;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Resource;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use Illuminate\Database\Seeder;

class SpaceSeeder extends Seeder
{
    /**
     * Seeds spatial hierarchy, business hours, spaces, and resources
     * matching the mobile app Browse Spaces interface.
     */
    public function run(): void
    {
        // 1. Main Branch
        $branch = Branch::query()->firstOrCreate(
            ['timezone' => 'Asia/Damascus'],
            [
                'name' => ['en' => 'Aleppo Digital District', 'ar' => 'حي حلب الرقمي'],
                'city' => ['en' => 'Aleppo', 'ar' => 'حلب'],
                'is_active' => true,
                'is_default' => true,
            ]
        );

        // 2. Working hours (8:00 AM - 10:00 PM every day)
        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::query()->firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'day_of_week' => $day->value,
                ],
                [
                    'open_time' => '08:00',
                    'close_time' => '22:00',
                ]
            );
        }

        // 3. Building
        $building = Building::query()->firstOrCreate(
            ['branch_id' => $branch->id],
            [
                'name' => ['en' => 'Main Innovation Hub', 'ar' => 'مبنى الابتكار الرئيسي'],
                'floor_count' => 3,
            ]
        );

        // 4. Floor
        $floor = Floor::query()->firstOrCreate(
            [
                'building_id' => $building->id,
                'label' => 'Ground Floor',
            ],
            [
                'sort_order' => 1,
            ]
        );

        // 5. Zone
        $zone = Zone::query()->firstOrCreate(
            [
                'floor_id' => $floor->id,
                'label' => 'Zone A - Workspaces & Meetings',
            ],
            [
                'sort_order' => 1,
            ]
        );

        // 6. Spaces matching the app UI:

        // --- Space 1: Hot Desk (Open Workspace / Co-Space) ---
        $hotDesk = Space::query()->firstOrCreate(
            [
                'building_id' => $building->id,
                'space_type' => SpaceType::CoSpace,
            ],
            [
                'name' => ['en' => 'Hot Desk', 'ar' => 'مكتب مرن'],
                'zone_id' => $zone->id,
                'allocation_model' => AllocationModel::Open,
                'is_lockable' => false,
                'capacity' => 1,
                'hourly_rate_points' => 30,
                'cancellation_window_minutes' => 60,
                'slot_granularity_minutes' => 60,
                'buffer_minutes' => 0,
                'requires_approval' => false,
                'status' => OperationalStatus::Active,
            ]
        );

        $this->seedResources($hotDesk, [
            ['name' => 'High-Speed Wi-Fi', 'category' => ResourceCategory::Other, 'quantity' => 1],
            ['name' => 'Desk Power Outlets', 'category' => ResourceCategory::Other, 'quantity' => 1],
            ['name' => 'Café Access', 'category' => ResourceCategory::Other, 'quantity' => 1],
        ]);

        for ($i = 1; $i <= 10; $i++) {
            SeatDesk::query()->firstOrCreate([
                'space_id' => $hotDesk->id,
                'label' => sprintf('Desk %02d', $i),
            ]);
        }

        // --- Space 2: Meeting Room A ---
        $meetingRoom = Space::query()->firstOrCreate(
            [
                'building_id' => $building->id,
                'space_type' => SpaceType::Room,
                'capacity' => 6,
            ],
            [
                'name' => ['en' => 'Meeting Room A', 'ar' => 'غرفة الاجتماعات أ'],
                'zone_id' => $zone->id,
                'allocation_model' => AllocationModel::BookingHourly,
                'is_lockable' => true,
                'hourly_rate_points' => 120,
                'cancellation_window_minutes' => 60,
                'slot_granularity_minutes' => 30,
                'buffer_minutes' => 0,
                'requires_approval' => false,
                'status' => OperationalStatus::Active,
            ]
        );

        $this->seedResources($meetingRoom, [
            ['name' => 'Presentation Screen', 'category' => ResourceCategory::Screen, 'quantity' => 1],
            ['name' => 'Whiteboard', 'category' => ResourceCategory::Whiteboard, 'quantity' => 1],
            ['name' => 'Air Conditioning (A/C)', 'category' => ResourceCategory::Other, 'quantity' => 1],
        ]);

        // --- Space 3: Private Office 3 ---
        $privateOffice = Space::query()->firstOrCreate(
            [
                'building_id' => $building->id,
                'space_type' => SpaceType::Business,
                'capacity' => 2,
            ],
            [
                'name' => ['en' => 'Private Office 3', 'ar' => 'مكتب خاص ٣'],
                'zone_id' => $zone->id,
                'allocation_model' => AllocationModel::BookingHourly,
                'is_lockable' => true,
                'hourly_rate_points' => 180,
                'cancellation_window_minutes' => 120,
                'slot_granularity_minutes' => 60,
                'buffer_minutes' => 0,
                'requires_approval' => false,
                'status' => OperationalStatus::Active,
            ]
        );

        $this->seedResources($privateOffice, [
            ['name' => 'Smart Lock Access', 'category' => ResourceCategory::Other, 'quantity' => 1],
            ['name' => 'Air Conditioning (A/C)', 'category' => ResourceCategory::Other, 'quantity' => 1],
        ]);

        // --- Space 4: Event Hall ---
        $eventHall = Space::query()->firstOrCreate(
            [
                'building_id' => $building->id,
                'space_type' => SpaceType::EventHall,
            ],
            [
                'name' => ['en' => 'Event Hall', 'ar' => 'قاعة الفعاليات'],
                'zone_id' => $zone->id,
                'allocation_model' => AllocationModel::BookingDaily,
                'is_lockable' => true,
                'capacity' => 80,
                'hourly_rate_points' => 1200,
                'cancellation_window_minutes' => 1440,
                'slot_granularity_minutes' => 60,
                'buffer_minutes' => 30,
                'requires_approval' => true,
                'status' => OperationalStatus::Active,
            ]
        );

        $this->seedResources($eventHall, [
            ['name' => 'Stage & Podium', 'category' => ResourceCategory::Other, 'quantity' => 1],
            ['name' => 'Laser Projector', 'category' => ResourceCategory::Projector, 'quantity' => 1],
            ['name' => 'Audio System & Microphones', 'category' => ResourceCategory::Mic, 'quantity' => 2],
        ]);
    }

    /**
     * @param  array<int, array{name: string, category: ResourceCategory, quantity: int}>  $resources
     */
    private function seedResources(Space $space, array $resources): void
    {
        foreach ($resources as $res) {
            Resource::query()->firstOrCreate(
                [
                    'space_id' => $space->id,
                    'name' => $res['name'],
                ],
                [
                    'category' => $res['category'],
                    'quantity' => $res['quantity'],
                    'status' => OperationalStatus::Active,
                ]
            );
        }
    }
}
