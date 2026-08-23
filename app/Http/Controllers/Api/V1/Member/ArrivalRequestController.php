<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Services\ArrivalRequestMatcher;
use App\Domain\Foundation\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Resources\ArrivalRequestResource;
use Illuminate\Http\Request;

class ArrivalRequestController extends Controller
{
    public function store(Request $request, ArrivalRequestMatcher $matcher): ArrivalRequestResource
    {
        $member = $request->user();
        $branch = Branch::query()->where('is_active', true)->first();
        $matchedBooking = $branch === null ? null : $matcher->matchBookingFor($member, $branch, now());

        $arrivalRequest = ArrivalRequest::create([
            'user_id' => $member->id,
            'requested_at' => now(),
            'matched_booking_id' => $matchedBooking?->id,
        ]);

        return new ArrivalRequestResource($arrivalRequest);
    }
}
