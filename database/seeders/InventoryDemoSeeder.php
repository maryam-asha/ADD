<?php

namespace Database\Seeders;

use Add\Hr\Core\Models\Employee;
use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Assets\Enums\MaintenanceType;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Assets\Services\MaintenanceService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Catalog\Services\CatalogService;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Place;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccountCreationService;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Retail\Models\SaleItem;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Demo data for the inventory package (packages/add/inventory), so every
 * screen and state shows in the dashboard: a category tree with its own
 * fields (IT equipment › laptops, displays, networking; furniture › chairs,
 * desks; consumables › office, café, cleaning, snacks), assets in ADD's own
 * spaces in every status — in service, under repair, retired, lost,
 * disposed of — with their purchase, depreciation, warranty (one about to
 * end), movements, custody (held, returned, handed on) and maintenance
 * (open, closed, a schedule due and one overdue); stock on several places
 * at average cost through receipts, issues, a transfer and counts (one
 * short, one over, one nearly empty); what reception sells; printing linked
 * to the paper it uses up.
 *
 * Everything goes through the package's own services, as the screens do,
 * so the data obeys every rule; the clock is set back while it runs so the
 * history spreads over the last months. Two accounts try the permissions,
 * password `password`: storekeeper@staff.demo (role store_keeper, every
 * inventory permission) and technician@staff.demo (role technician, assets
 * and maintenance only).
 *
 * Local and staging only; runs once (skipped when its items exist):
 * `php artisan db:seed --class=InventoryDemoSeeder`.
 */
class InventoryDemoSeeder extends Seeder
{
    private const SKU = 'DEMO-INV-';

    private CarbonImmutable $today;

    /** @var array<string, Place> */
    private array $places = [];

    /** @var array<string, Unit> */
    private array $units = [];

    /** @var array<string, Category> */
    private array $categories = [];

    /** @var array<string, Item> */
    private array $items = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('InventoryDemoSeeder holds demo stock and never runs in production.');
        }

        if (Item::query()->where('sku', 'like', self::SKU.'%')->exists()) {
            $this->command?->warn('Inventory demo data already exists; InventoryDemoSeeder left it as it is.');

            return;
        }

        if (! Space::query()->exists()) {
            $this->call(SpaceSeeder::class);
        }

        if (! ResourceType::query()->where('slug', 'printing')->exists()) {
            $this->call(ResourceTypeSeeder::class);
        }

        if (! Permission::query()->where('name', Abilities::ASSETS_VIEW)->exists()) {
            $this->call(PermissionSeeder::class);
        }

        $this->today = CarbonImmutable::today();
        $this->resolvePlaces();

        try {
            DB::transaction(function () {
                $this->seedCatalog();
                $this->seedAssets();
                $this->seedStock();
                $this->seedSales();
                $this->seedAccounts();
            });
        } finally {
            $this->at(null);
        }

        $this->command?->info(sprintf(
            'Inventory demo data seeded: %d items, %d assets, %d stock balances.',
            Item::query()->where('sku', 'like', self::SKU.'%')->count(),
            Asset::query()->whereIn('item_id', collect($this->items)->pluck('id'))->count(),
            StockBalance::query()->whereIn('item_id', collect($this->items)->pluck('id'))->where('quantity', '>', 0)->count(),
        ));
    }

    // ── Places ──────────────────────────────────────────────────────────────

    /**
     * ADD's own hierarchy, as SpaceSeeder lays it out: the branch's
     * reception (where the desk sells and records printing from), the
     * building's IT room, a floor's store cupboard, a zone's café corner,
     * and the four spaces.
     */
    private function resolvePlaces(): void
    {
        $branch = Branch::query()->orderBy('id')->firstOrFail();
        $building = Building::query()->where('branch_id', $branch->id)->orderBy('id')->firstOrFail();
        $floor = Floor::query()->where('building_id', $building->id)->orderBy('id')->first();
        $zone = $floor ? Zone::query()->where('floor_id', $floor->id)->orderBy('id')->first() : null;
        $space = fn (SpaceType $type) => Space::query()->where('building_id', $building->id)->where('space_type', $type)->orderBy('id')->first();

        $this->places = array_filter([
            'reception' => new Place('branch', $branch->id),
            'it_room' => new Place('building', $building->id),
            'store' => $floor ? new Place('floor', $floor->id) : null,
            'cafe' => $zone ? new Place('zone', $zone->id) : null,
            'hot_desk' => ($s = $space(SpaceType::CoSpace)) ? new Place('space', $s->id) : null,
            'meeting_room' => ($s = $space(SpaceType::Room)) ? new Place('space', $s->id) : null,
            'office' => ($s = $space(SpaceType::Business)) ? new Place('space', $s->id) : null,
            'event_hall' => ($s = $space(SpaceType::EventHall)) ? new Place('space', $s->id) : null,
        ]);

        // A building with no floors or spaces of its own still gets every
        // record, kept at the building.
        foreach (['store', 'cafe', 'hot_desk', 'meeting_room', 'office', 'event_hall'] as $key) {
            $this->places[$key] ??= $this->places['it_room'];
        }
    }

    // ── Catalog ─────────────────────────────────────────────────────────────

    private function seedCatalog(): void
    {
        $catalog = app(CatalogService::class);

        $unit = fn (string $code, string $ar, string $en, bool $fractions = false) => $this->units[$code] = Unit::query()->firstOrCreate(
            ['code' => $code],
            ['name' => ['ar' => $ar, 'en' => $en], 'allows_fractions' => $fractions],
        );
        $unit('piece', 'قطعة', 'Piece');
        $unit('sheet', 'ورقة', 'Sheet');
        $unit('kg', 'كيلوغرام', 'Kilogram', true);
        $unit('liter', 'لتر', 'Litre', true);
        $unit('box', 'علبة', 'Box');

        $category = function (string $key, string $ar, string $en, ?string $parent = null, ItemKind $kind = ItemKind::Asset, bool $members = false, ?string $icon = null) use ($catalog) {
            // A child takes its parent's kind; only a root names one.
            return $this->categories[$key] = $catalog->createCategory([
                'name' => ['ar' => $ar, 'en' => $en],
                'icon' => $icon,
                'show_to_members' => $members,
                ...($parent ? ['parent_id' => $this->categories[$parent]->id] : ['kind' => $kind->value]),
            ]);
        };
        $field = fn (string $category, string $key, string $ar, string $en, string $type, array $extra = []) => $catalog->defineAttribute(
            $this->categories[$category],
            ['key' => $key, 'label' => ['ar' => $ar, 'en' => $en], 'type' => $type, ...$extra],
        );

        $category('it', 'معدات تقنية', 'IT equipment', icon: 'laptop');
        $field('it', 'brand', 'الماركة', 'Brand', 'text', ['is_required' => true]);
        $field('it', 'model', 'الطراز', 'Model', 'text');
        $category('laptops', 'حواسيب محمولة', 'Laptops', 'it', icon: 'laptop');
        $field('laptops', 'ram_gb', 'الذاكرة', 'Memory', 'number', ['unit' => 'GB']);
        $field('laptops', 'cpu', 'المعالج', 'Processor', 'text');
        $category('displays', 'شاشات', 'Displays', 'it', members: true, icon: 'screen');
        $field('displays', 'resolution', 'الدقة', 'Resolution', 'select', ['options' => ['1080p', '1440p', '4k'], 'is_required' => true]);
        $field('displays', 'size', 'القياس', 'Size', 'number', ['unit' => 'inch']);
        $category('network', 'معدات شبكة', 'Networking', 'it', icon: 'network-3');
        $field('network', 'ports', 'عدد المنافذ', 'Ports', 'number');

        $category('furniture', 'أثاث', 'Furniture', icon: 'building');
        $field('furniture', 'colour', 'اللون', 'Colour', 'select', ['options' => ['black', 'grey', 'white', 'oak']]);
        $category('chairs', 'كراسي', 'Chairs', 'furniture');
        $category('desks', 'مكاتب', 'Desks', 'furniture');
        $field('desks', 'width_cm', 'العرض', 'Width', 'number', ['unit' => 'cm']);

        $category('consumables', 'مواد استهلاكية', 'Consumables', kind: ItemKind::Stock);
        $category('office', 'قرطاسية', 'Office supplies', 'consumables');
        $category('cafe', 'مواد الكافيه', 'Café supplies', 'consumables');
        $field('cafe', 'origin', 'المنشأ', 'Origin', 'text');
        $category('cleaning', 'مواد تنظيف', 'Cleaning', 'consumables');
        $category('snacks', 'مشروبات ووجبات خفيفة', 'Drinks & snacks', 'consumables');

        $item = function (string $key, string $category, string $unit, string $ar, string $en, array $properties = []) use ($catalog) {
            return $this->items[$key] = $catalog->createItem([
                'category_id' => $this->categories[$category]->id,
                'unit_id' => $this->units[$unit]->id,
                'sku' => self::SKU.strtoupper(str_replace('_', '-', $key)),
                'name' => ['ar' => $ar, 'en' => $en],
                'properties' => $properties,
            ]);
        };

        $item('dell_laptop', 'laptops', 'piece', 'حاسوب Dell Latitude 5440', 'Dell Latitude 5440', ['brand' => 'Dell', 'model' => 'Latitude 5440', 'ram_gb' => 16, 'cpu' => 'Core i7']);
        $item('macbook', 'laptops', 'piece', 'MacBook Air M2', 'MacBook Air M2', ['brand' => 'Apple', 'model' => 'MacBook Air M2', 'ram_gb' => 8, 'cpu' => 'M2']);
        $item('monitor', 'displays', 'piece', 'شاشة Samsung قياس 27', 'Samsung 27" monitor', ['brand' => 'Samsung', 'resolution' => '1440p', 'size' => 27]);
        $item('wall_tv', 'displays', 'piece', 'شاشة حائط LG قياس 65', 'LG 65" wall TV', ['brand' => 'LG', 'resolution' => '4k', 'size' => 65]);
        $item('switch', 'network', 'piece', 'مبدّل Cisco بـ 24 منفذاً', 'Cisco 24-port switch', ['brand' => 'Cisco', 'model' => 'CBS250-24T', 'ports' => 24]);
        $item('chair', 'chairs', 'piece', 'كرسي مريح', 'Ergonomic chair', ['colour' => 'black']);
        $item('desk', 'desks', 'piece', 'مكتب قابل لتعديل الارتفاع', 'Standing desk', ['colour' => 'oak', 'width_cm' => 140]);

        $item('paper', 'office', 'sheet', 'ورق A4', 'A4 paper');
        $item('pens', 'office', 'box', 'أقلام حبر (علبة)', 'Ballpoint pens (box)');
        $item('coffee', 'cafe', 'kg', 'بن عربي', 'Arabic coffee beans', ['origin' => 'Brazil']);
        $item('milk', 'cafe', 'liter', 'حليب طازج', 'Fresh milk');
        $item('cups', 'cafe', 'box', 'أكواب ورقية (علبة)', 'Paper cups (box)');
        $item('detergent', 'cleaning', 'liter', 'منظف أرضيات', 'Floor detergent');
        $item('soap', 'cleaning', 'piece', 'صابون يدين', 'Hand soap');
        $item('water', 'snacks', 'piece', 'مياه معدنية', 'Bottled water');
        $item('cola', 'snacks', 'piece', 'مشروب غازي', 'Cola can');
        $item('chocolate', 'snacks', 'piece', 'لوح شوكولا', 'Chocolate bar');
        $item('notebook', 'office', 'piece', 'دفتر ملاحظات', 'Notebook');
    }

    // ── Assets ──────────────────────────────────────────────────────────────

    private function seedAssets(): void
    {
        $assets = app(AssetService::class);
        $maintenance = app(MaintenanceService::class);

        $money = fn (int $monthsAgo, string $cost, int $life, string $salvage, ?string $warranty = null) => [
            'purchased_on' => $this->today->subMonths($monthsAgo)->toDateString(),
            'purchase_cost' => $cost,
            'currency' => 'USD',
            'useful_life_months' => $life,
            'salvage_value' => $salvage,
            'supplier' => 'Aleppo Tech Supplies',
            'warranty_until' => $warranty,
        ];

        // Registered when they were bought, so their history starts then.
        $this->at($this->today->subMonths(14));
        $dells = $assets->register($this->items['dell_laptop'], $this->places['it_room'], $money(14, '950', 36, '100', $this->today->addDays(20)->toDateString()), 4)->all();
        $switch = $assets->register($this->items['switch'], $this->places['store'], [...$money(14, '400', 60, '40'), 'serial_number' => 'FOC2231X0AB'])->first();
        $chairs = $assets->register($this->items['chair'], $this->places['hot_desk'], $money(14, '150', 60, '10'), 10)->all();
        $desks = $assets->register($this->items['desk'], $this->places['office'], $money(14, '300', 84, '30'), 4)->all();

        $this->at($this->today->subMonths(10));
        $tv = $assets->register($this->items['wall_tv'], $this->places['event_hall'], [...$money(10, '1100', 60, '100', $this->today->addYear()->toDateString()), 'serial_number' => 'LG65UR-0091'])->first();
        $monitors = $assets->register($this->items['monitor'], $this->places['meeting_room'], $money(10, '280', 48, '20', $this->today->addMonths(8)->toDateString()), 3)->all();

        $this->at($this->today->subMonths(6));
        $macs = $assets->register($this->items['macbook'], $this->places['it_room'], $money(6, '1200', 36, '200', $this->today->addMonths(6)->toDateString()), 2)->all();

        // Serial numbers, one by one.
        foreach ([...$dells, ...$macs] as $i => $laptop) {
            $laptop->update(['serial_number' => sprintf('SN-%s-%04d', $laptop->item_id, $i + 1)]);
        }

        // Movements: a monitor lent to the event hall and brought back.
        $this->at($this->today->subMonths(3));
        $assets->move($monitors[2], $this->places['event_hall'], 'For the investors evening');
        $this->at($this->today->subMonths(3)->addDays(2));
        $assets->move($monitors[2], $this->places['meeting_room'], 'Back after the event');

        // Custody: to employees when HR has them, else to members; desks to
        // the company in the private office.
        [$first, $second] = $this->custodians();
        $this->at($this->today->subMonths(4));
        if ($first) {
            $assets->assign($dells[0], $first, 'Work laptop');
        }
        if ($second) {
            $assets->assign($dells[1], $second, 'Work laptop');
            $this->at($this->today->subMonths(1));
            $assets->takeBack($dells[1], 'Returned on leaving the team');
        }
        $this->at($this->today->subDays(10));
        if ($first) {
            $assets->assign($macs[0], $second ?? $first, 'Design work');
        }
        if ($company = Company::query()->orderBy('id')->first()) {
            $this->at($this->today->subMonths(2));
            $assets->assign($desks[0], new Place('company', $company->id), 'Furniture of the leased office');
            $assets->assign($desks[1], new Place('company', $company->id), 'Furniture of the leased office');
        }

        // Maintenance: a repair still open, two closed, schedules due and overdue.
        $this->at($this->today->subMonths(5));
        $repair = $maintenance->open($chairs[0], MaintenanceType::Corrective, 'Gas lift sinks', 'Office Repairs Co.');
        $this->at($this->today->subMonths(5)->addDays(4));
        $maintenance->close($repair, '25', 'USD', 'Gas lift replaced');

        $calibration = $tv->maintenanceSchedules()->create(['title' => 'Picture calibration', 'interval_months' => 6, 'next_due_on' => $this->today->subMonths(2)->toDateString()]);
        $this->at($this->today->subMonths(2));
        $service = $maintenance->open($tv, MaintenanceType::Preventive, 'Six-monthly calibration', 'LG Service', $calibration);
        $this->at($this->today->subMonths(2)->addDay());
        $maintenance->close($service, '60', 'USD', 'Calibrated, firmware updated');
        // Closing moved it on six months; set it back so it shows overdue.
        $calibration->update(['next_due_on' => $this->today->subDays(10)->toDateString()]);

        $switch->maintenanceSchedules()->create(['title' => 'Firmware check', 'interval_months' => 6, 'next_due_on' => $this->today->addDays(3)->toDateString()]);
        $dells[2]->maintenanceSchedules()->create(['title' => 'Battery health check', 'interval_months' => 12, 'next_due_on' => $this->today->addMonths(4)->toDateString()]);

        $this->at($this->today->subDays(3));
        $maintenance->open($dells[3], MaintenanceType::Corrective, 'Battery does not charge', 'Aleppo Tech Supplies');

        // The other statuses.
        $this->at($this->today->subMonths(1));
        $assets->setStatus($chairs[8], AssetStatus::Retired);
        $assets->setStatus($chairs[7], AssetStatus::Lost);
        $assets->dispose($chairs[9], DisposalMethod::Sold, $this->today->subMonths(1), '40', 'Sold to a member');
    }

    /**
     * @return array{0: ?Place, 1: ?Place}
     */
    private function custodians(): array
    {
        $employees = Employee::query()->orderBy('id')->limit(2)->pluck('id');

        if ($employees->count() === 2) {
            return [new Place('hr_employee', $employees[0]), new Place('hr_employee', $employees[1])];
        }

        $members = User::role('member')->orderBy('id')->limit(2)->pluck('id');

        while ($members->count() < 2) {
            $n = $members->count() + 1;
            $members->push(app(AccountCreationService::class)->create([
                'name' => "عضو تجريبي {$n}",
                'phone' => sprintf('+9639445080%02d', $n),
                'email' => "inventory-member{$n}@member.demo",
            ], 'member', 'password')->id);
        }

        return [new Place('member', $members[0]), new Place('member', $members[1])];
    }

    // ── Stock ───────────────────────────────────────────────────────────────

    private function seedStock(): void
    {
        $stock = app(StockService::class);
        $p = $this->places;
        $i = $this->items;

        // Paper at reception (printing draws on it), two deliveries at two prices.
        $this->at($this->today->subMonths(3));
        $stock->receive($i['paper'], $p['reception'], 3000, '0.01', note: 'Delivery 1');
        $this->at($this->today->subMonths(1));
        $stock->receive($i['paper'], $p['reception'], 2000, '0.0125', note: 'Delivery 2');
        $stock->transfer($i['paper'], $p['reception'], $p['store'], 1000, 'To the store cupboard');
        $this->at($this->today->subDays(12));
        $stock->issue($i['paper'], $p['store'], 150, IssueReason::InternalUse, note: 'Staff printing');
        $stock->issue($i['paper'], $p['store'], 40, IssueReason::Damaged, note: 'Water damage');
        $stock->receive($i['pens'], $p['store'], 12, '3.5');
        $stock->issue($i['pens'], $p['store'], 4, IssueReason::InternalUse);

        // The café corner: re-averaged coffee, a count that came up short.
        $this->at($this->today->subMonths(2));
        $stock->receive($i['coffee'], $p['cafe'], 8, '14', note: 'Roastery order');
        $stock->receive($i['milk'], $p['cafe'], 20, '1.2');
        $stock->receive($i['cups'], $p['cafe'], 10, '6');
        $this->at($this->today->subDays(20));
        $stock->receive($i['coffee'], $p['cafe'], 4, '16', note: 'Roastery order');
        $stock->issue($i['coffee'], $p['cafe'], 2.5, IssueReason::InternalUse, note: 'Staff coffee');
        $stock->issue($i['milk'], $p['cafe'], 6, IssueReason::InternalUse);
        $stock->issue($i['milk'], $p['cafe'], 2, IssueReason::Expired);
        $stock->issue($i['cups'], $p['cafe'], 7, IssueReason::InternalUse);
        $this->at($this->today->subDays(2));
        $stock->adjust($i['coffee'], $p['cafe'], 9.2, note: 'Monthly count');

        // Cleaning: soap nearly gone.
        $this->at($this->today->subMonths(1));
        $stock->receive($i['detergent'], $p['store'], 15, '2');
        $stock->receive($i['soap'], $p['store'], 40, '1.5');
        $stock->issue($i['detergent'], $p['store'], 4.5, IssueReason::InternalUse);
        $stock->issue($i['soap'], $p['store'], 37, IssueReason::InternalUse);

        // What reception sells, on its own shelf; a count that found more.
        $this->at($this->today->subDays(15));
        $stock->receive($i['water'], $p['reception'], 120, '0.25');
        $stock->receive($i['cola'], $p['reception'], 48, '0.5');
        $stock->receive($i['chocolate'], $p['reception'], 60, '0.4');
        $stock->receive($i['notebook'], $p['reception'], 25, '1.2');
        $stock->issue($i['water'], $p['reception'], 6, IssueReason::Damaged, note: 'Crushed in delivery');
        $this->at($this->today->subDays(1));
        $stock->adjust($i['cola'], $p['reception'], 50, '0.5', 'Found an extra pack');
    }

    // ── Reception ───────────────────────────────────────────────────────────

    private function seedSales(): void
    {
        foreach (['water' => 10, 'cola' => 15, 'chocolate' => 12, 'notebook' => 30] as $key => $points) {
            SaleItem::query()->create(['inventory_item_id' => $this->items[$key]->id, 'price_points' => $points]);
        }

        // A printed page uses up one sheet, from the reception's paper.
        ResourceType::query()->where('slug', 'printing')->update([
            'inventory_item_id' => $this->items['paper']->id,
            'stock_units_per_unit' => 1,
        ]);
    }

    // ── Accounts ────────────────────────────────────────────────────────────

    private function seedAccounts(): void
    {
        $roles = [
            'store_keeper' => [Abilities::all(), 'أمين المستودع (تجريبي)', '+963944508101', 'storekeeper@staff.demo'],
            'technician' => [[Abilities::CATALOG_VIEW, Abilities::ASSETS_VIEW, Abilities::ASSETS_MAINTENANCE], 'فني الصيانة (تجريبي)', '+963944508102', 'technician@staff.demo'],
        ];

        foreach ($roles as $name => [$permissions, $person, $phone, $email]) {
            Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'])->syncPermissions($permissions);

            if (! User::query()->where('email', $email)->exists()) {
                app(AccountCreationService::class)->create(['name' => $person, 'phone' => $phone, 'email' => $email], $name, 'password');
            }
        }
    }

    // ── Clock ───────────────────────────────────────────────────────────────

    /** Sets the clock the services stamp their rows with; null puts it back. */
    private function at(?CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
