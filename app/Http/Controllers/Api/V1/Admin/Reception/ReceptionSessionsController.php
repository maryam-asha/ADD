<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Membership\Services\DedicatedDeskService;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveReceptionSessionResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReceptionSessionsController extends Controller
{
    public function active(SessionClosureService $closures, SettingService $settings, DedicatedDeskService $desks): AnonymousResourceCollection
    {
        $timezone = $settings->get('app.timezone', 'Asia/Damascus');
        $now = Carbon::now($timezone);

        $bookings = Booking::where('status', BookingStatus::Confirmed)
            ->whereNotNull('checked_in_at')->whereNull('checked_out_at')
            ->with('space.building.branch', 'user.personalProfile', 'user.companies', 'pendingEndRequest')->get();

        $walkins = WalkinSession::whereNotNull('checked_in_at')->whereNull('checked_out_at')
            ->with('space.building.branch', 'user.personalProfile', 'user.companies', 'pendingEndRequest')->get();

        $bookings->each(function (Booking $booking) use ($closures, $now): void {
            $booking->type = 'booking';
            $booking->is_overdue = $this->isOverdue($closures, $booking, $now);
        });

        $walkins->each(function (WalkinSession $walkin) use ($closures, $now): void {
            $walkin->type = 'walkin';
            $walkin->is_overdue = $this->isOverdue($closures, $walkin, $now);
        });

        $sessions = $bookings->concat($walkins);

        // The desk a Dedicated Desk member sits at, so reception can send
        // them to it (docs/decisions/coworking-seat-pool.md).
        $codes = $desks->deskCodesByUser($sessions->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all());
        $sessions->each(fn ($session) => $session->desk_codes = $codes[$session->user_id] ?? []);

        return ActiveReceptionSessionResource::collection($sessions);
    }

    public function report(string $type, int $id, CoworkingSessionService $sessions, SessionReportBuilder $reports): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null, 404);

        return response()->json(['data' => $reports->build($session)]);
    }

    private function isOverdue(SessionClosureService $closures, Booking|WalkinSession $session, Carbon $now): bool
    {
        $branch = $session->space->building->branch;
        $closingTime = $closures->closingTimeFor($branch, $now);

        return $closingTime !== null && $now->format('H:i') > $closingTime;
    }
}
