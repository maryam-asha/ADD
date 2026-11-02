<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\User;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Services\ServiceRequestService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * A member hears when staff move their service request on — in progress,
 * resolved, rejected — named by its service, in their own language.
 */
class ServiceRequestNoticesTest extends TestCase
{
    use ReadsNotices;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
    }

    public function test_a_status_change_tells_the_member(): void
    {
        $request = $this->serviceRequest(RequestStatus::Pending);

        app(ServiceRequestService::class)->updateStatus($request, RequestStatus::InProgress);

        $notice = $this->assertNoticed($request->user_id, 'service_request.status_updated');
        $this->assertSame('Your Printing request is now in progress.', $this->noticeText($notice, 'en')['body']);
        $this->assertSame('أصبحت حالة طلب طباعة: قيد التنفيذ.', $this->noticeText($notice, 'ar')['body']);
    }

    public function test_a_note_alone_tells_nobody(): void
    {
        $request = $this->serviceRequest(RequestStatus::InProgress);

        app(ServiceRequestService::class)->updateStatus($request, RequestStatus::InProgress, ['en' => 'Working on it']);

        $this->assertDatabaseCount('notifications', 0);
    }

    private function serviceRequest(RequestStatus $status): ServiceRequest
    {
        $type = ServiceType::create([
            'service_axis_id' => ServiceAxis::create(['slug' => 'office', 'name' => ['en' => 'Office', 'ar' => 'المكتب'], 'color' => '#334155'])->id,
            'slug' => 'printing',
            'name' => ['ar' => 'طباعة', 'en' => 'Printing'],
            'request_method' => 'form',
            'is_active' => true,
        ]);

        return ServiceRequest::create(['service_type_id' => $type->id, 'user_id' => User::factory()->create()->id, 'status' => $status]);
    }
}
