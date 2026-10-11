<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->wallets = app(WalletService::class);
    }

    private function createMemberWithWallet(int $initialPoints = 0): array
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'phone' => '0912345678',
        ]);
        $user->assignRole('member');

        $wallet = Wallet::create([
            'owner_type' => OwnerType::User,
            'owner_id' => $user->id,
        ]);

        if ($initialPoints > 0) {
            $this->wallets->creditGeneral($wallet, $initialPoints, WalletTransactionSource::TopUp, 'Initial test points');
        }

        return [$user, $wallet];
    }

    public function test_member_can_register_for_free_event_without_wallet_deduction(): void
    {
        [$user, $wallet] = $this->createMemberWithWallet(100);

        $event = Event::create([
            'title' => ['en' => 'Free Meetup', 'ar' => 'لقاء مجاني'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'location' => 'Main Hall',
            'capacity' => 50,
            'is_free' => true,
            'price_points' => 0,
            'status' => 'published',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/member/events/{$event->id}/register");
        $response->assertCreated();
        $response->assertJsonPath('data.event.id', $event->id);
        $response->assertJsonMissingPath('data.attendee');
        $response->assertJsonMissingPath('data.payment');
        $response->assertJsonMissingPath('data.registered_at');

        $this->assertDatabaseHas('event_attendees', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'email' => 'john.doe@example.com',
            'name' => 'John Doe',
        ]);

        // Wallet balance untouched
        $this->assertSame(100, $this->wallets->generalBalance($wallet));
    }

    public function test_member_can_register_for_paid_event_with_sufficient_points(): void
    {
        [$user, $wallet] = $this->createMemberWithWallet(500);

        $event = Event::create([
            'title' => ['en' => 'AI Masterclass', 'ar' => 'دورة ذكاء اصطناعي'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(3),
            'location' => 'Tech Room',
            'capacity' => 30,
            'is_free' => false,
            'price_points' => 150,
            'status' => 'published',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/member/events/{$event->id}/register");
        $response->assertCreated();
        $response->assertJsonPath('data.event.id', $event->id);
        $response->assertJsonMissingPath('data.payment');
        $response->assertJsonMissingPath('data.attendee');
        $response->assertJsonMissingPath('data.registered_at');

        $this->assertDatabaseHas('event_attendees', [
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        // Exactly 150 points deducted from wallet
        $this->assertSame(350, $this->wallets->generalBalance($wallet));
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'amount' => '-150',
        ]);
    }

    public function test_member_cannot_register_for_paid_event_with_insufficient_points(): void
    {
        [$user, $wallet] = $this->createMemberWithWallet(50);

        $event = Event::create([
            'title' => ['en' => 'Executive Seminar', 'ar' => 'ندوة تنفيذية'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'capacity' => 20,
            'is_free' => false,
            'price_points' => 150,
            'status' => 'published',
        ]);

        Sanctum::actingAs($user, ['*']);

        $response = $this->postJson("/api/v1/member/events/{$event->id}/register");
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['points']);

        // No attendee record created
        $this->assertDatabaseMissing('event_attendees', [
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        // Balance untouched
        $this->assertSame(50, $this->wallets->generalBalance($wallet));
    }

    public function test_cannot_register_twice_for_same_event(): void
    {
        [$user, $wallet] = $this->createMemberWithWallet(500);

        $event = Event::create([
            'title' => ['en' => 'Design Workshop', 'ar' => 'ورشة تصميم'],
            'start_at' => now()->addDays(4),
            'end_at' => now()->addDays(4)->addHours(2),
            'capacity' => 20,
            'is_free' => false,
            'price_points' => 50,
            'status' => 'published',
        ]);

        Sanctum::actingAs($user, ['*']);

        // First registration succeeds
        $response = $this->postJson("/api/v1/member/events/{$event->id}/register");
        $response->assertCreated();

        // Second registration fails with clear translated message
        $response = $this->withHeaders(['lang' => 'ar'])
            ->postJson("/api/v1/member/events/{$event->id}/register");
        $response->assertStatus(422);
        $response->assertJsonPath('errors.registration.0', 'أنت مسجل بالفعل في هذه الفعالية.');

        // Test in English
        $responseEn = $this->withHeaders(['lang' => 'en'])
            ->postJson("/api/v1/member/events/{$event->id}/register");
        $responseEn->assertStatus(422);
        $responseEn->assertJsonPath('errors.registration.0', 'You are already registered for this event.');

        // Only 50 points deducted, not 100
        $this->assertSame(450, $this->wallets->generalBalance($wallet));
    }

    public function test_cannot_register_when_event_capacity_is_full(): void
    {
        [$user1, $wallet1] = $this->createMemberWithWallet(100);
        $user2 = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '0912345679',
        ]);
        $user2->assignRole('member');

        $event = Event::create([
            'title' => ['en' => 'Limited Seat Talk', 'ar' => 'حديث مقاعد محدودة'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'capacity' => 1,
            'is_free' => true,
            'price_points' => 0,
            'status' => 'published',
        ]);

        // First member takes the only seat
        Sanctum::actingAs($user1, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();

        // Second member tries to register (Arabic)
        Sanctum::actingAs($user2, ['*']);
        $response = $this->withHeaders(['lang' => 'ar'])
            ->postJson("/api/v1/member/events/{$event->id}/register");
        $response->assertStatus(422);
        $response->assertJsonPath('errors.capacity.0', 'عذراً، هذه الفعالية ممتلئة وجميع المقاعد محجوزة بالكامل.');

        // Second member tries to register (English)
        $responseEn = $this->withHeaders(['lang' => 'en'])
            ->postJson("/api/v1/member/events/{$event->id}/register");
        $responseEn->assertStatus(422);
        $responseEn->assertJsonPath('errors.capacity.0', 'Sorry, this event is full. All seats are completely booked.');
    }

    public function test_cannot_register_for_past_or_cancelled_event(): void
    {
        [$user, $wallet] = $this->createMemberWithWallet(100);
        Sanctum::actingAs($user, ['*']);

        // Past event
        $pastEvent = Event::create([
            'title' => ['en' => 'Past Event', 'ar' => 'فعالية سابقة'],
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addHours(2),
            'is_free' => true,
            'status' => 'published',
        ]);

        $this->postJson("/api/v1/member/events/{$pastEvent->id}/register")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);

        // Cancelled event
        $cancelledEvent = Event::create([
            'title' => ['en' => 'Cancelled Event', 'ar' => 'فعالية ملغاة'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'is_free' => true,
            'status' => 'cancelled',
        ]);

        $this->postJson("/api/v1/member/events/{$cancelledEvent->id}/register")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['event']);
    }
}
