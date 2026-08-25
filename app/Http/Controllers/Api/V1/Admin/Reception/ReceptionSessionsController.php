<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveReceptionSessionResource;
use Carbon\Carbon;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReceptionSessionsController extends Controller
{
    public function active(SessionClosureService $closures, SettingService $settings): AnonymousResourceCollection
    {
        $timezone = $settings->get('app.timezone', 'Asia/Damascus');
        $now = Carbon::now($timezone);

        $bookings = Booking::where('status', BookingStatus::Confirmed)
            ->whereNotNull('checked_in_at')->whereNull('checked_out_at')
            ->with('space.building.branch', 'user')->get();

        $walkins = WalkinSession::whereNotNull('checked_in_at')->whereNull('checked_out_at')
            ->with('space.building.branch', 'user')->get();

        $bookings->each(function (Booking $booking) use ($closures, $now): void {
            $booking->type = 'booking';
            $booking->is_overdue = $this->isOverdue($closures, $booking, $now);
        });

        $walkins->each(function (WalkinSession $walkin) use ($closures, $now): void {
            $walkin->type = 'walkin';
            $walkin->is_overdue = $this->isOverdue($closures, $walkin, $now);
        });

        $sessions = $bookings->concat($walkins);

        return ActiveReceptionSessionResource::collection($sessions);
    }

    private function isOverdue(SessionClosureService $closures, Booking|WalkinSession $session, Carbon $now): bool
    {
        $branch = $session->space->building->branch;
        $closingTime = $closures->closingTimeFor($branch, $now);

        return $closingTime !== null && $now->format('H:i') > $closingTime;
    }
}
