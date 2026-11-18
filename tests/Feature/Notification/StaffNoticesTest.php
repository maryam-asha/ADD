<?php

namespace Tests\Feature\Notification;

use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Services\ServiceRequestService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\Feature\Renewals\RenewalsTestCase;

/**
 * What waits for staff reaches whoever may act on it — by the permission
 * that acts on it, in the dashboard, never on a member's phone.
 */
class StaffNoticesTest extends RenewalsTestCase
{
    use ReadsNotices;

    private User $staff;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->staff = User::factory()->create(['name' => 'Staff']);
        $this->staff->assignRole('admin');
        $this->member = User::factory()->create(['name' => 'Sara']);
        $this->member->assignRole('member');
    }

    public function test_a_new_service_request_reaches_whoever_handles_them(): void
    {
        Role::create(['name' => 'facilities', 'guard_name' => 'web'])->givePermissionTo('service-requests.update');
        $handler = User::factory()->create();
        $handler->assignRole('facilities');
        $type = ServiceType::create([
            'service_axis_id' => ServiceAxis::create(['slug' => 'office', 'name' => ['en' => 'Office', 'ar' => 'المكتب'], 'color' => '#334155'])->id,
            'slug' => 'printing',
            'name' => ['ar' => 'طباعة', 'en' => 'Printing'],
            'request_method' => 'form',
            'is_active' => true,
        ]);

        app(ServiceRequestService::class)->submit($type, $this->member, []);

        $notice = $this->assertNoticed($handler, 'service_request.submitted', NotificationAudience::Staff);
        $this->assertSame('Sara requested Printing.', $this->noticeText($notice, 'en')['body']);
        $this->assertNotNoticed($this->member, 'service_request.submitted');
    }

    public function test_a_provider_request_reaches_its_reviewers(): void
    {
        $provider = Provider::factory()->create(['name' => 'Hub']);
        $company = Company::factory()->create(['provider_id' => $provider->id, 'legal_name' => 'Acme']);

        app(ProviderRequestService::class)->submit($provider, $this->member, ProviderRequestType::WalletTopUp, $company->id, ['points' => 100, 'payer' => 'company']);

        $text = $this->noticeText($this->assertNoticed($this->staff, 'provider_request.submitted', NotificationAudience::Staff), 'en');
        $this->assertSame('New request from Hub', $text['title']);
        $this->assertSame('Request: a wallet top-up for Acme.', $text['body']);
    }

    public function test_a_provider_office_request_reaches_its_reviewers(): void
    {
        $provider = Provider::factory()->create(['name' => 'Hub']);
        $account = User::factory()->create();
        $account->assignRole('provider');
        $provider->users()->attach($account->id);
        Sanctum::actingAs($account, ['*']);

        $this->postJson('/api/v1/provider/office-requests', ['prospect_name' => 'Nova', 'contact' => '+963912345678', 'payer' => 'company'])->assertCreated();

        $text = $this->noticeText($this->assertNoticed($this->staff, 'private_office.requested', NotificationAudience::Staff), 'en');
        $this->assertSame('Hub requested a private office for Nova.', $text['body']);
    }

    public function test_a_registration_awaiting_approval_reaches_whoever_decides(): void
    {
        $event = Event::factory()->create([
            'title' => ['ar' => 'محاضرة', 'en' => 'Talk'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHour(),
            'requires_approval' => true,
            'is_free' => true,
            'status' => 'published',
        ]);
        Sanctum::actingAs($this->member, ['member-app']);

        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();

        $text = $this->noticeText($this->assertNoticed($this->staff, 'event.registration_pending', NotificationAudience::Staff), 'en');
        $this->assertSame('Sara registered for Talk.', $text['body']);
    }
}
