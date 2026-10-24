<?php

namespace Database\Seeders;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Support\Place;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\SpaceAmenity;
use App\Domain\Foundation\Models\SpaceTypeAmenity;
use App\Domain\Foundation\Models\Zone;
use Illuminate\Database\Seeder;

class SpaceSeeder extends Seeder
{
    /**
     * Seeds spatial hierarchy, business hours, spaces, their amenities and
     * the equipment in them, matching the mobile app Browse Spaces interface.
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
                // Recounted from its desks below (SeatDesk).
                'capacity' => 0,
                'hourly_rate_points' => 15, // 1.5 USD (price sheet, 2026-10-08)
                'cancellation_window_minutes' => 60,
                'slot_granularity_minutes' => 60,
                'buffer_minutes' => 0,
                'requires_approval' => false,
                'status' => OperationalStatus::Active,
            ]
        );

        for ($i = 1; $i <= 10; $i++) {
            SeatDesk::query()->firstOrCreate(
                ['space_id' => $hotDesk->id, 'code' => sprintf('D-%02d', $i)],
                ['label' => sprintf('Desk %02d', $i)],
            );
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

        $this->seedEquipment($meetingRoom, [
            ['Screens', 'الشاشات', 'screen', 'Presentation Screen', 'شاشة عرض', 1],
            ['Whiteboards', 'الألواح البيضاء', 'whiteboard', 'Whiteboard', 'لوح أبيض', 1],
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

        $this->seedEquipment($eventHall, [
            ['Projectors', 'أجهزة العرض', 'projector', 'Laser Projector', 'جهاز عرض ليزر', 1],
            ['Microphones', 'الميكروفونات', 'volume-2', 'Audio System & Microphones', 'نظام صوت وميكروفونات', 2],
        ]);

        // 7. Type -> amenity config matrix (admin-editable default starting
        // point — see Amenity/SpaceTypeAmenity), then turn on every
        // amenity applicable to each demo space's own type. Keyed by the
        // enum's string value, not the enum case itself — PHP array keys
        // can't be enum instances.
        $amenityMatrix = [
            SpaceType::CoSpace->value => [
                'wifi', 'power_outlet', 'ergonomic_chairs',
                'printing', 'locker', 'cafe_access',
            ],
            SpaceType::Room->value => [
                'wifi', 'power_outlet', 'ac', 'cafe_access',
            ],
            SpaceType::Business->value => [
                'wifi', 'power_outlet', 'ac',
                'locker', 'cafe_access',
            ],
            SpaceType::EventHall->value => [
                'wifi', 'power_outlet', 'ac',
                'cafe_access', 'stage',
            ],
        ];

        foreach ($amenityMatrix as $typeValue => $amenities) {
            foreach ($amenities as $amenity) {
                SpaceTypeAmenity::query()->firstOrCreate([
                    'space_type' => SpaceType::from($typeValue),
                    'amenity' => $amenity,
                ]);
            }
        }

        $spacesByType = [
            SpaceType::CoSpace->value => $hotDesk,
            SpaceType::Room->value => $meetingRoom,
            SpaceType::Business->value => $privateOffice,
            SpaceType::EventHall->value => $eventHall,
        ];

        foreach ($spacesByType as $typeValue => $space) {
            foreach ($amenityMatrix[$typeValue] as $amenity) {
                SpaceAmenity::query()->firstOrCreate([
                    'space_id' => $space->id,
                    'amenity' => $amenity,
                ]);
            }
        }
    }

    /**
     * Equipment members see in the space: inventory assets under
     * "Presentation equipment" (shown to members), registered through
     * AssetService. Rerunning tops a space up to the count, never past it.
     * Each row: category en, category ar, icon, item en, item ar, count.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int}>  $equipment
     */
    private function seedEquipment(Space $space, array $equipment): void
    {
        $unit = Unit::query()->firstOrCreate(['code' => 'piece'], ['name' => ['en' => 'Piece', 'ar' => 'قطعة']]);
        $root = Category::query()->whereNull('parent_id')->where('icon', 'projector')->first()
            ?? Category::query()->create([
                'kind' => ItemKind::Asset,
                'name' => ['en' => 'Presentation equipment', 'ar' => 'معدات العرض'],
                'icon' => 'projector',
                'show_to_members' => true,
            ]);

        foreach ($equipment as [$categoryEn, $categoryAr, $icon, $itemEn, $itemAr, $count]) {
            $category = Category::query()->where('parent_id', $root->id)->where('icon', $icon)->first()
                ?? Category::query()->create([
                    'parent_id' => $root->id,
                    'kind' => ItemKind::Asset,
                    'name' => ['en' => $categoryEn, 'ar' => $categoryAr],
                    'icon' => $icon,
                    'show_to_members' => true,
                ]);
            $item = Item::query()->where('category_id', $category->id)->get()->first(fn (Item $item) => $item->name['en'] === $itemEn)
                ?? Item::query()->create([
                    'category_id' => $category->id,
                    'unit_id' => $unit->id,
                    'sku' => 'DEMO-'.strtoupper(substr(md5($itemEn), 0, 6)),
                    'name' => ['en' => $itemEn, 'ar' => $itemAr],
                ]);

            $missing = $count - Asset::query()->where('item_id', $item->id)->where('location_type', 'space')->where('location_id', $space->id)->count();

            if ($missing > 0) {
                app(AssetService::class)->register($item, new Place('space', $space->id), [], $missing);
            }
        }
    }
}
