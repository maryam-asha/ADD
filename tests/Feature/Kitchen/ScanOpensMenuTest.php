<?php

namespace Tests\Feature\Kitchen;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\QrPointPlace;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Kitchen\Models\QrPoint;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * The app's one scan button also opens the kitchen menu: a desk or room
 * code answers outcome "menu"; kiosk codes and lock stickers behave as before.
 */
class ScanOpensMenuTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Branch $branch;

    private SeatDesk $desk;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);

        $this->branch = Branch::factory()->create();
        $building = Building::factory()->create(['branch_id' => $this->branch->id]);
        $space = Space::factory()->create(['building_id' => $building->id]);
        $this->desk = SeatDesk::factory()->create(['space_id' => $space->id, 'label' => 'Desk 7']);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);
    }

    private function deskQr(): QrPoint
    {
        return QrPoint::factory()->create([
            'branch_id' => $this->branch->id,
            'place_type' => QrPointPlace::SeatDesk,
            'place_id' => $this->desk->id,
        ]);
    }

    public function test_scanning_a_desk_point_answers_menu(): void
    {
        $qr = $this->deskQr();

        $this->postJson('/api/v1/member/scan', ['qr_value' => ' '.$qr->scanValue().' '])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'menu')
            ->assertJsonPath('data.qr', $qr->scanValue())
            ->assertJsonPath('data.place.type', 'seat_desk')
            ->assertJsonPath('data.place.id', $this->desk->id)
            ->assertJsonPath('data.place.label', 'Desk 7')
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.can_order', false)
            ->assertJsonPath('data.reason', 'no_kitchen');
    }

    public function test_a_kitchen_and_no_session_may_still_order(): void
    {
        $kitchen = Warehouse::factory()->create(['place_type' => 'branch', 'place_id' => $this->branch->id]);
        KitchenStore::create(['branch_id' => $this->branch->id, 'warehouse_id' => $kitchen->id]);

        $this->postJson('/api/v1/member/scan', ['qr_value' => $this->deskQr()->scanValue()])
            ->assertOk()
            ->assertJsonPath('data.can_order', true)
            ->assertJsonPath('data.reason', null);
    }

    public function test_unknown_place_code_is_422(): void
    {
        $this->postJson('/api/v1/member/scan', ['qr_value' => 'add-place:v1:nope'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.scan.unknown_code'));
    }

    public function test_a_point_whose_desk_is_gone_is_unknown(): void
    {
        $qr = $this->deskQr();
        $this->desk->delete();

        $this->postJson('/api/v1/member/scan', ['qr_value' => $qr->scanValue()])->assertStatus(422);
        $this->assertNull(QrPoint::fromScan($qr->scanValue()));
    }

    public function test_lock_stickers_still_unlock(): void
    {
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'sticker-1']);
        AccessGrant::factory()->activated()->create([
            'lock_id' => $lock->id, 'grantee_type' => 'user', 'grantee_id' => $this->member->id,
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/v1/member/scan', ['qr_value' => 'sticker-1'])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'unlocked');
    }

    public function test_kiosk_codes_still_start_coworking(): void
    {
        $space = $this->coworkingSpace();
        [$kiosk, $secret] = $this->pairedKiosk($space);
        $this->subscribe($this->member, $this->planWithHours(40));

        $this->postJson('/api/v1/member/scan', ['qr_value' => $this->qrFor($kiosk, $secret)])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation');
    }
}
